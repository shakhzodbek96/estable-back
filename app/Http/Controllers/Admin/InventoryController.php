<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BulkStoreInventoryRequest;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Services\InventoryImportService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name']);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'ilike', "%{$search}%")
                  ->orWhere('extra_serial_number', 'ilike', "%{$search}%");
            });
        }

        if ($productSearch = $request->string('product_search')->trim()->value()) {
            $query->whereHas('product', function ($q) use ($productSearch) {
                $q->where('name', 'ilike', "%{$productSearch}%");
            });
        }

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($investorId = $request->integer('investor_id')) {
            $query->where('investor_id', $investorId);
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $data = [
            ...$request->validated(),
            'serials' => [['serial_number' => $request->serial_number, 'extra_serial_number' => $request->extra_serial_number]],
        ];

        $inventories = $this->inventoryService->createBatch($data);

        return response()->json($inventories->first()->load(['product:id,name', 'shop:id,name']), 201);
    }

    public function bulkStore(BulkStoreInventoryRequest $request): JsonResponse
    {
        $inventories = $this->inventoryService->createBatch($request->validated());

        $ids = $inventories->pluck('id');
        $loaded = Inventory::with(['product:id,name', 'shop:id,name'])->whereIn('id', $ids)->get();

        return response()->json([
            'message' => $loaded->count() . ' ta tovar yaratildi',
            'data' => $loaded,
        ], 201);
    }

    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load([
            'product:id,name,type,category_id',
            'product.category:id,name',
            'shop:id,name',
            'investor:id,name,phone',
            'creator:id,name',
            'repairCosts' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return response()->json($inventory);
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventory): JsonResponse
    {
        $inventory = $this->inventoryService->updateItem($inventory, $request->validated());
        $inventory->load(['product:id,name', 'shop:id,name', 'investor:id,name']);

        return response()->json($inventory);
    }

    public function destroy(Inventory $inventory): JsonResponse
    {
        if ($inventory->status !== InventoryStatus::InStock) {
            return response()->json(['message' => 'Faqat in_stock tovarni o\'chirish mumkin'], 422);
        }

        $inventory->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Serial inventarni rich format bilan import qilish.
     *
     * Har qatorda alohida tovar, narxlar, holat bo'ladi (9 ustun).
     * Faqat shop va investor umumiy — formada tanlanadi.
     *
     * Form-data:
     *   - file (XLSX/CSV/TXT, max 3 MB)
     *   - shop_id (required)
     *   - investor_id (nullable)
     *   - auto_create_products (boolean, default false) — yo'q tovarlar avto-yaratilsinmi?
     */
    public function import(Request $request, InventoryImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:3072',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,application/zip',
            ],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
            'auto_create_products' => ['sometimes', 'boolean'],
        ]);

        try {
            $rows = $importer->extractRichRows($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            report($e);
            return response()->json([
                'message' => 'Файл повреждён или защищён паролем. Сохраните как обычный .xlsx без защиты.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Не удалось разобрать файл. Попробуйте использовать наш шаблон или сохраните как .csv.',
            ], 422);
        }

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'Файл пуст или не содержит корректных строк (нужны product и imei).'], 422);
        }

        // 1. IMEI'larni DB bilan solishtirish
        $existingImeis = Inventory::whereIn('serial_number', $rows->pluck('imei')->all())
            ->pluck('serial_number')
            ->values();
        $rowsAfterImei = $rows->reject(fn ($r) => $existingImeis->contains($r['imei']))->values();

        if ($rowsAfterImei->isEmpty()) {
            return response()->json([
                'total_lines' => $rows->count(),
                'created_count' => 0,
                'skipped_count' => $existingImeis->count(),
                'skipped_imeis' => $existingImeis->take(20)->values(),
                'unknown_products' => [],
                'message' => 'Все IMEI из файла уже есть в системе.',
            ], 200);
        }

        // 2. Tovar nomlarini tizimda tekshirish
        $productNames = $rowsAfterImei->pluck('product_name')->unique()->values();
        $existingProducts = \App\Models\Product::whereIn('name', $productNames->all())
            ->select('id', 'name', 'type')
            ->get()
            ->keyBy('name');

        $missingNames = $productNames->reject(fn ($name) => $existingProducts->has($name))->values();
        $autoCreate = (bool) ($data['auto_create_products'] ?? false);
        $createdProducts = collect();

        if ($missingNames->isNotEmpty()) {
            if (!$autoCreate) {
                return response()->json([
                    'message' => 'Не найдены товары в системе. Включите опцию автоматического создания или создайте их вручную.',
                    'unknown_products' => $missingNames->take(50)->values(),
                ], 422);
            }
            // Auto-create — type=serial, kategoriya null. Bulletproof:
            //   1. firstOrCreate — atomically idempotent
            //   2. catch — UniqueConstraintViolationException + QueryException SQLSTATE 23505
            //      (Laravel versiyasi qarab har xil class chiqishi mumkin)
            foreach ($missingNames as $name) {
                try {
                    $product = \App\Models\Product::firstOrCreate(
                        ['name' => $name],
                        ['category_id' => null, 'type' => 'serial']
                    );
                    if ($product->wasRecentlyCreated) {
                        $createdProducts->push($product->name);
                    }
                    $existingProducts->put($name, $product);
                } catch (\Throwable $e) {
                    if (\App\Services\ProductImportService::isUniqueViolation($e)) {
                        // Race condition — boshqa user bir vaqtda yaratdi.
                        // Qaytadan o'qib, mavjud product'ni olib qo'yamiz.
                        $existingProduct = \App\Models\Product::where('name', $name)->first();
                        if ($existingProduct) {
                            $existingProducts->put($name, $existingProduct);
                        }
                        continue;
                    }
                    throw $e;
                }
            }
        }

        // 3. Rich payload yig'ish — har qator product_id'ga ega bo'lishini KAFOLATLAYMIZ
        $unresolvedNames = collect();
        $richRows = $rowsAfterImei->map(function ($r) use ($existingProducts, $unresolvedNames) {
            $product = $existingProducts->get($r['product_name']);
            if (!$product || !$product->id) {
                $unresolvedNames->push($r['product_name']);
                return null;
            }
            return [
                'product_id' => $product->id,
                'serial_number' => $r['imei'],
                'extra_serial_number' => $r['imei2'],
                'purchase_price' => $r['purchase'],
                'selling_price' => $r['price'],
                'wholesale_price' => $r['retail'],
                'state' => $r['condition'],
                'has_box' => $r['has_box'],
                'notes' => $r['note'],
            ];
        })->filter()->values();

        // ★ FAIL-FAST: agar bironta tovar resolve bo'lmasa, hech narsa saqlanmasin
        // (createRichBatch transaction'i qisman ma'lumotni qoldirmasligi uchun)
        if ($unresolvedNames->isNotEmpty()) {
            return response()->json([
                'message' => 'Не удалось привязать товары. Некоторые названия не найдены и не созданы. Ничего не сохранено.',
                'unresolved_products' => $unresolvedNames->unique()->take(50)->values(),
            ], 500);
        }

        if ($richRows->isEmpty()) {
            return response()->json([
                'message' => 'После обработки не осталось строк для импорта.',
            ], 422);
        }

        // 4. Yana bir bor IMEI uniqueness tekshiruvi (race condition)
        $validator = Validator::make(['rows' => $richRows->all()], [
            'rows.*.serial_number' => ['required', 'string', 'max:255', 'distinct', 'unique:inventories,serial_number'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Часть IMEI повторяется или уже существует',
                'errors' => $validator->errors(),
            ], 422);
        }

        $created = $this->inventoryService->createRichBatch([
            'shop_id' => $data['shop_id'],
            'investor_id' => $data['investor_id'] ?? null,
            'rows' => $richRows->all(),
        ]);

        return response()->json([
            'total_lines' => $rows->count(),
            'created_count' => $created->count(),
            'skipped_count' => $existingImeis->count(),
            'skipped_imeis' => $existingImeis->take(20)->values(),
            'auto_created_products' => $createdProducts->take(20)->values(),
        ], 201);
    }

    /**
     * Serial inventory uchun XLSX shablon.
     */
    public function importTemplate(InventoryImportService $importer): StreamedResponse
    {
        $spreadsheet = $importer->generateTemplate();

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new XlsxWriter($spreadsheet);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'inventory-import-template.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]
        );
    }

    public function search(Request $request): JsonResponse
    {
        $serial = $request->string('serial')->trim()->value();

        if (!$serial) {
            return response()->json(['data' => []]);
        }

        $results = Inventory::with(['product:id,name,type', 'shop:id,name'])
            ->where('serial_number', 'ilike', "%{$serial}%")
            ->orWhere('extra_serial_number', 'ilike', "%{$serial}%")
            ->limit(20)
            ->get();

        return response()->json(['data' => $results]);
    }

    public function byStatus(string $status): JsonResponse
    {
        $inventories = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name'])
            ->where('status', $status)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($inventories);
    }
}
