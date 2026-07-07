<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InventoryStatus;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkUpdateInventoryPriceRequest;
use App\Http\Requests\Inventory\BulkStoreInventoryRequest;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Services\InventoryImportService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $query = Inventory::with(['product:id,name,type', 'product.primaryImage', 'primaryImage', 'shop:id,name', 'investor:id,name']);

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

        // Karobka holati: "1"/"0" (true/false)
        if ($request->filled('has_box')) {
            $query->where('has_box', $request->boolean('has_box'));
        }

        // Tovar holati: new / used
        if ($state = $request->string('state')->trim()->value()) {
            $query->where('state', $state);
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }

        // investor_id: raqam → o'sha investor; 'none' → investorsiz (NULL) zaxira.
        if ($request->filled('investor_id')) {
            $investorId = $request->input('investor_id');
            if ($investorId === 'none') {
                $query->whereNull('investor_id');
            } elseif (is_numeric($investorId)) {
                $query->where('investor_id', (int) $investorId);
            }
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $data = [
            ...$validated,
            'serials' => [[
                'serial_number' => $request->serial_number,
                'extra_serial_number' => $request->extra_serial_number,
                // Yagona qo'shishda ham xususiyatlar serial ichida (createBatch per-serial o'qiydi)
                'custom_attributes' => $validated['custom_attributes'] ?? null,
            ]],
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
            'message' => 'Создано товаров: ' . $loaded->count(),
            'data' => $loaded,
        ], 201);
    }

    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load([
            'product:id,name,type,category_id',
            'product.category:id,name',
            'product.images',
            'images',
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

        // Investor mablag'iga olingan bo'lsa — xaridni teskari hisoblab keyin o'chiradi
        $this->inventoryService->deleteItem($inventory);

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
            // Партия / Поставщик (ixtiyoriy, butun faylga bitta) — накладная, sana, to'lov rejimi.
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id', Rule::requiredIf(fn () => $request->input('payment_mode') === 'credit')],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'batch_date' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'in:paid,credit'],
        ], [
            'supplier_id.required' => 'Для покупки в долг выберите поставщика.',
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

        // 1. IMEI'larni DB bilan solishtirish — faqat skladda turganlar (sotilgan serial qayta kiritilishi mumkin)
        $existingImeis = Inventory::whereIn('serial_number', $rows->pluck('imei')->all())
            ->where('status', InventoryStatus::InStock)
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
        $createdProducts = collect();

        if ($missingNames->isNotEmpty()) {
            // Yetishmayotgan tovarlar doim avtomatik yaratiladi — type=serial, kategoriya null.
            // Bulletproof:
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
                'custom_attributes' => $r['custom_attributes'] ?? [],
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
            'rows.*.serial_number' => ['required', 'string', 'max:255', 'distinct', Rule::unique('inventories', 'serial_number')->where('status', InventoryStatus::InStock->value)],
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
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'batch_date' => $data['batch_date'] ?? null,
            'payment_mode' => $data['payment_mode'] ?? null,
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

    /**
     * Model bo'yicha ommaviy narx yangilashdan oldin preview:
     * o'sha product_id li in_stock donalar soni + joriy narx (selling/wholesale) oralig'i.
     */
    public function pricePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'state' => ['nullable', 'in:new,used'],
        ]);

        $stats = Inventory::query()
            ->where('product_id', $data['product_id'])
            ->where('status', InventoryStatus::InStock)
            ->when($request->input('state'), fn ($q, $state) => $q->where('state', $state))
            ->selectRaw('
                COUNT(*) as count,
                MIN(selling_price) as min_selling_price,
                MAX(selling_price) as max_selling_price,
                MIN(wholesale_price) as min_wholesale_price,
                MAX(wholesale_price) as max_wholesale_price
            ')
            ->first();

        return response()->json([
            'product_id' => $data['product_id'],
            'count' => (int) $stats->count,
            'selling_price' => [
                'min' => $stats->min_selling_price !== null ? (float) $stats->min_selling_price : null,
                'max' => $stats->max_selling_price !== null ? (float) $stats->max_selling_price : null,
            ],
            'wholesale_price' => [
                'min' => $stats->min_wholesale_price !== null ? (float) $stats->min_wholesale_price : null,
                'max' => $stats->max_wholesale_price !== null ? (float) $stats->max_wholesale_price : null,
            ],
        ]);
    }

    /**
     * price-search / bulkUpdatePrice uchun umumiy filtr: status=in_stock
     * + product_id / supply_batch_id / invoice_number (накладная, partial) / state / ids.
     */
    private function filteredInStockQuery(array $filters)
    {
        return Inventory::query()
            ->where('status', InventoryStatus::InStock)
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['supply_batch_id'] ?? null, fn ($q, $v) => $q->where('supply_batch_id', $v))
            ->when($filters['invoice_number'] ?? null, fn ($q, $v) => $q->whereHas(
                'supplyBatch',
                fn ($sq) => $sq->where('invoice_number', 'ilike', "%{$v}%")
            ))
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v))
            ->when($filters['ids'] ?? null, fn ($q, $v) => $q->whereIn('id', $v));
    }

    /**
     * Filtrlangan qidiruv — narx boshqarish sahifasi uchun (model/partiya/накладная/holat bo'yicha).
     * Kamida bitta filtr talab qilinadi — butun sklad qaytmasligi uchun.
     */
    public function priceSearch(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'supply_batch_id' => ['nullable', 'integer', 'exists:supply_batches,id'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'in:new,used'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if (!($filters['product_id'] ?? null) && !($filters['supply_batch_id'] ?? null) && !($filters['invoice_number'] ?? null) && !($filters['state'] ?? null)) {
            return response()->json(['message' => 'Укажите хотя бы один фильтр'], 422);
        }

        $limit = min(max($filters['limit'] ?? 100, 1), 500);

        $baseQuery = $this->filteredInStockQuery($filters);

        $stats = (clone $baseQuery)->selectRaw('
                COUNT(*) as count,
                MIN(selling_price) as min_selling_price,
                MAX(selling_price) as max_selling_price,
                MIN(wholesale_price) as min_wholesale_price,
                MAX(wholesale_price) as max_wholesale_price
            ')
            ->first();

        $items = (clone $baseQuery)
            ->with(['product:id,name', 'supplyBatch:id,invoice_number,batch_date'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'serial_number', 'product_id', 'state', 'selling_price', 'wholesale_price', 'supply_batch_id']);

        $count = (int) $stats->count;

        return response()->json([
            'items' => $items,
            'total_count' => $count,
            'selling_price' => [
                'min' => $count > 0 && $stats->min_selling_price !== null ? (float) $stats->min_selling_price : null,
                'max' => $count > 0 && $stats->max_selling_price !== null ? (float) $stats->max_selling_price : null,
            ],
            'wholesale_price' => [
                'min' => $count > 0 && $stats->min_wholesale_price !== null ? (float) $stats->min_wholesale_price : null,
                'max' => $count > 0 && $stats->max_wholesale_price !== null ? (float) $stats->max_wholesale_price : null,
            ],
        ]);
    }

    /**
     * Ommaviy narx yangilash — model/partiya/накладная/holat yoki aniq ID ro'yxati bo'yicha.
     * Faqat selling_price / wholesale_price — purchase_price (tannarx) ga tegilmaydi.
     * Faqat status=in_stock (sotilmagan) donalar yangilanadi.
     */
    public function bulkUpdatePrice(BulkUpdateInventoryPriceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $payload = [];
        if (array_key_exists('selling_price', $data) && $data['selling_price'] !== null) {
            $payload['selling_price'] = $data['selling_price'];
        }
        if (array_key_exists('wholesale_price', $data) && $data['wholesale_price'] !== null) {
            $payload['wholesale_price'] = $data['wholesale_price'];
        }

        $updated = DB::transaction(function () use ($data, $payload) {
            return $this->filteredInStockQuery($data)
                ->lockForUpdate()
                ->update($payload);
        });

        return response()->json([
            'updated_count' => $updated,
            'new_selling_price' => $data['selling_price'] ?? null,
            'new_wholesale_price' => $data['wholesale_price'] ?? null,
        ]);
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
