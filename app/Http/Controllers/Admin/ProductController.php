<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with('category:id,name');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($categoryId = $request->integer('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if ($type = $request->string('type')->trim()->value()) {
            $query->where('type', $type);
        }

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());
        $product->load('category:id,name');

        return response()->json($product, 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category:id,name');

        return response()->json($product);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());
        $product->load('category:id,name');

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Bir vaqtning o'zida bir nechta mahsulot yaratish.
     * Body: { category_id?, type, names: ["Item 1", "Item 2"] }
     *
     * Dublikat tekshiruv:
     *   - Massiv ichidagi takrorlanishlar (->unique())
     *   - DB'da active + soft-deleted (withTrashed)
     *   - Race condition — try/catch QueryException 23505
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'type' => ['required', 'in:serial,bulk'],
            'names' => ['required', 'array', 'min:1', 'max:1000'],
            'names.*' => ['required', 'string', 'max:255'],
        ]);

        $names = collect($data['names'])
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique()
            ->values();

        // ★ withTrashed() — soft-deleted ham hisobga
        $existing = Product::withTrashed()
            ->whereIn('name', $names->all())
            ->pluck('name')
            ->values();

        $toCreate = $names->diff($existing)->values();

        $created = collect();
        $raceSkipped = collect();

        foreach ($toCreate as $name) {
            try {
                $product = Product::create([
                    'category_id' => $data['category_id'] ?? null,
                    'type' => $data['type'],
                    'name' => $name,
                ]);
                $created->push($product);
            } catch (\Illuminate\Database\QueryException $e) {
                if (
                    $e->getCode() === '23505'
                    || str_contains($e->getMessage(), 'Duplicate entry')
                    || str_contains($e->getMessage(), 'duplicate key')
                ) {
                    $raceSkipped->push($name);
                    continue;
                }
                throw $e;
            }
        }

        $created->each->load('category:id,name');
        $allSkipped = $existing->merge($raceSkipped);

        return response()->json([
            'created' => $created,
            'count' => $created->count(),
            'skipped_count' => $allSkipped->count(),
            'skipped_names' => $allSkipped->take(20)->values(),
        ], 201);
    }

    /**
     * Mahsulotlarni fayldan import qilish (XLSX / CSV / TXT).
     *
     * Xavfsizlik:
     *   - 3 MB qattiq cheklov (validatsiya + nginx client_max_body_size)
     *   - Magic bytes orqali fayl turini aniqlash (kengaytmaga ishonilmaydi)
     *   - 5000 satrgacha (DoS oldini olish — ProductImportService::MAX_ROWS)
     *   - PhpSpreadsheet read-only rejimda (formula evaluation o'chiq)
     *   - CSV injection — `=`/`+`/`-`/`@` bilan boshlanadigan qiymatlar zararsizlantiriladi
     *   - Eski .xls (OLE) qabul qilinmaydi (faqat .xlsx)
     *
     * Form-data:
     *   - file (XLSX/CSV/TXT, max 3 MB)
     *   - type (required: serial|bulk)
     *   - category_id (optional)
     */
    public function import(Request $request, ProductImportService $service): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                // 3 MB = 3072 KB
                'max:3072',
                // MIME tekshiruvi: matn fayllar va xlsx variantlari (kengaytmaga ishonilmaydi —
                // xizmatda magic bytes bilan ham qo'shimcha tekshiruv qilinadi)
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,application/zip',
            ],
            'type' => ['required', 'in:serial,bulk'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        try {
            $names = $service->extractNames($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            // Reader-level xato — fayl o'qilmaydi (buzilgan, parol bilan, noto'g'ri format)
            report($e);
            return response()->json([
                'message' => 'Файл повреждён или защищён паролем. Сохраните как обычный .xlsx без защиты и попробуйте снова.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Не удалось разобрать файл. Попробуйте использовать наш шаблон или сохраните как .csv.',
            ], 422);
        }

        if ($names->isEmpty()) {
            return response()->json([
                'message' => 'Файл пуст или не содержит названий товаров.',
            ], 422);
        }

        $result = $service->persist($names, $data['type'], $data['category_id'] ?? null);

        return response()->json([
            'total_lines' => $names->count(),
            ...$result,
        ], 201);
    }

    /**
     * XLSX shablon (misol bilan) — foydalanuvchi yuklab olib, to'ldirib, qaytadan upload qiladi.
     */
    public function importTemplate(ProductImportService $service): StreamedResponse
    {
        $spreadsheet = $service->generateTemplate();

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new XlsxWriter($spreadsheet);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'products-import-template.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]
        );
    }
}
