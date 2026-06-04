<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accessory\BulkStoreAccessoryRequest;
use App\Http\Requests\Accessory\RestockAccessoryRequest;
use App\Http\Requests\Accessory\StoreAccessoryRequest;
use App\Http\Requests\Accessory\UpdateAccessoryRequest;
use App\Models\Accessory;
use App\Models\Product;
use App\Services\AccessoryImportService;
use App\Services\AccessoryService;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessoryController extends Controller
{
    public function __construct(
        private AccessoryService $accessoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Accessory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name']);

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($barcode = $request->string('barcode')->trim()->value()) {
            $query->where('barcode', 'ilike', "%{$barcode}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($investorId = $request->integer('investor_id')) {
            $query->where('investor_id', $investorId);
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreAccessoryRequest $request): JsonResponse
    {
        $accessory = $this->accessoryService->createBatch($request->validated());
        $accessory->load(['product:id,name', 'shop:id,name']);

        return response()->json($accessory, 201);
    }

    /**
     * Bir nechta aksessuar partiyasini bitta so'rov bilan yaratish.
     * Serial (IMEI) ga o'xshash UX: bir product + bir invoice + bir investor
     * bo'yicha N ta barcode/quantity/narx partiyasi.
     */
    public function bulkStore(BulkStoreAccessoryRequest $request): JsonResponse
    {
        $accessories = $this->accessoryService->createBulkBatches($request->validated());

        $ids = $accessories->pluck('id');
        $loaded = Accessory::with(['product:id,name', 'shop:id,name'])->whereIn('id', $ids)->get();

        return response()->json([
            'message' => 'Создано партий: ' . $loaded->count(),
            'data' => $loaded,
        ], 201);
    }

    public function show(Accessory $accessory): JsonResponse
    {
        $accessory->load([
            'product:id,name,type,category_id',
            'product.category:id,name',
            'shop:id,name',
            'investor:id,name,phone',
            'creator:id,name',
        ]);

        return response()->json($accessory);
    }

    public function update(UpdateAccessoryRequest $request, Accessory $accessory): JsonResponse
    {
        try {
            $accessory = $this->accessoryService->updateItem($accessory, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $accessory->load(['product:id,name', 'shop:id,name']);

        return response()->json($accessory);
    }

    public function destroy(Accessory $accessory): JsonResponse
    {
        if ($accessory->sold_quantity > 0) {
            return response()->json(['message' => 'Sotilgan partiyani o\'chirish mumkin emas'], 422);
        }

        $accessory->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function search(Request $request): JsonResponse
    {
        $barcode = $request->string('barcode')->trim()->value();
        $shopId = $request->integer('shop_id');

        if (!$barcode) {
            return response()->json(['data' => null]);
        }

        $accessory = $shopId
            ? $this->accessoryService->findForSale($barcode, $shopId)
            : Accessory::with('product:id,name')->where('barcode', $barcode)->where('is_active', true)->first();

        return response()->json(['data' => $accessory?->load('product:id,name')]);
    }

    /**
     * Bulk aksessuar partiyalarini fayldan import qilish.
     *
     * Har qator o'z tovariga (Наименование) bog'lanadi — tizimda yo'q bo'lsa
     * type=bulk bilan avtomatik yaratiladi, keyin import bajariladi.
     *
     * Form-data:
     *   - file (XLSX/CSV/TXT, max 3 MB) — qatorlar: name, barcode, qty, purchase, sell, wholesale?, notes?
     *   - shop_id, invoice_number, investor_id? — umumiy fieldlar
     */
    public function import(Request $request, AccessoryImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required', 'file', 'max:3072',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,application/zip',
            ],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
        ]);

        try {
            $batches = $importer->extractBatches($request->file('file'));
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

        if ($batches->isEmpty()) {
            return response()->json([
                'message' => 'Файл пуст или не содержит корректных партий (нужны наименование и штрих-код).',
            ], 422);
        }

        // Tovar nomlarini tizimda tekshirish, yetishmaganlarini avto-yaratish (type=bulk)
        $productNames = $batches->pluck('product_name')->unique()->values();
        $existingProducts = Product::whereIn('name', $productNames->all())
            ->select('id', 'name')
            ->get()
            ->keyBy('name');

        $missingNames = $productNames->reject(fn ($name) => $existingProducts->has($name))->values();
        $createdProducts = collect();

        foreach ($missingNames as $name) {
            try {
                $product = Product::firstOrCreate(
                    ['name' => $name],
                    ['category_id' => null, 'type' => 'bulk']
                );
                if ($product->wasRecentlyCreated) {
                    $createdProducts->push($product->name);
                }
                $existingProducts->put($name, $product);
            } catch (\Throwable $e) {
                // Race condition — boshqa so'rov bir vaqtda yaratgan bo'lishi mumkin
                if (ProductImportService::isUniqueViolation($e)) {
                    $existing = Product::where('name', $name)->first();
                    if ($existing) {
                        $existingProducts->put($name, $existing);
                    }
                    continue;
                }
                throw $e;
            }
        }

        // Har partiyaga product_id biriktirish — barchasi resolve bo'lishini kafolatlaymiz
        $unresolved = collect();
        $batchesWithProduct = $batches->map(function ($b) use ($existingProducts, $unresolved) {
            $product = $existingProducts->get($b['product_name']);
            if (!$product || !$product->id) {
                $unresolved->push($b['product_name']);
                return null;
            }
            return array_merge($b, ['product_id' => $product->id]);
        })->filter()->values();

        // FAIL-FAST: bironta tovar resolve bo'lmasa — hech narsa saqlanmaydi
        if ($unresolved->isNotEmpty()) {
            return response()->json([
                'message' => 'Не удалось привязать товары к партиям. Ничего не сохранено.',
                'unresolved_products' => $unresolved->unique()->take(50)->values(),
            ], 500);
        }

        $payload = [
            'shop_id' => $data['shop_id'],
            'invoice_number' => $data['invoice_number'],
            'investor_id' => $data['investor_id'] ?? null,
            'batches' => $batchesWithProduct->all(),
        ];

        $created = $this->accessoryService->createBulkBatches($payload);

        return response()->json([
            'total_lines' => $batches->count(),
            'created_count' => $created->count(),
            'skipped_count' => 0,
            'auto_created_products' => $createdProducts->take(20)->values(),
        ], 201);
    }

    /**
     * Bulk uchun XLSX shablon.
     */
    public function importTemplate(AccessoryImportService $importer): StreamedResponse
    {
        $spreadsheet = $importer->generateTemplate();

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new XlsxWriter($spreadsheet);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'accessory-import-template.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]
        );
    }

    public function restock(RestockAccessoryRequest $request, Accessory $accessory): JsonResponse
    {
        $accessory = $this->accessoryService->restock($accessory, $request->validated()['quantity']);
        $accessory->load(['product:id,name', 'shop:id,name']);

        return response()->json($accessory);
    }
}
