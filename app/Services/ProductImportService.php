<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Import\SpreadsheetParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Mahsulotlarni fayldan import qilish — TXT / CSV / XLSX qo'llab-quvvatlanadi.
 *
 * Xavfsizlik:
 *   - Magic bytes orqali fayl turini aniqlash (kengaytmaga ishonmaslik)
 *   - Faqat birinchi sheet va birinchi ustun olinadi
 *   - Maks 5000 satr (DoS oldini olish)
 *   - Har nom 255 belgiga qisqartiriladi
 *   - Read-only PhpSpreadsheet rejimi (formula evaluation o'chiq → CSV injection xavfsiz)
 */
class ProductImportService
{
    public function __construct(
        private SpreadsheetParser $parser,
    ) {}

    /** Sarlavha satrlarini avtomatik o'tkazib yuborish ro'yxati */
    private const HEADER_KEYWORDS = ['name', 'название', 'наименование', 'nomi', 'tovar', 'товар'];

    /**
     * Fayldan tovar nomlari ro'yxatini chiqarib oladi.
     *
     * @return Collection<int, string> Unique nomlar
     */
    public function extractNames(UploadedFile $file): Collection
    {
        $rows = $this->parser->parseRows($file, maxColumns: 1);

        return collect($rows)
            ->map(fn ($row) => $this->parser->sanitizeCell($row[0] ?? ''))
            ->filter()
            ->reject(fn ($v) => in_array(mb_strtolower($v), self::HEADER_KEYWORDS, true))
            ->map(fn ($v) => mb_substr($v, 0, 255))
            ->unique()
            ->values();
    }

    /**
     * Tovarlarni bazaga yozadi (mavjudlarini o'tkazib yuborib).
     *
     * @return array{created_count: int, skipped_count: int, skipped_names: array<string>}
     */
    public function persist(Collection $names, string $type, ?int $categoryId): array
    {
        $existing = Product::whereIn('name', $names->all())
            ->pluck('name')
            ->values();

        $toCreate = $names->diff($existing)->values();

        $created = $toCreate->map(fn ($name) => Product::create([
            'category_id' => $categoryId,
            'type' => $type,
            'name' => $name,
        ]));

        return [
            'created_count' => $created->count(),
            'skipped_count' => $existing->count(),
            'skipped_names' => $existing->take(20)->values()->all(),
        ];
    }

    /**
     * Misol XLSX shablonini yaratadi (Spreadsheet object).
     */
    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Товары');

        // Header
        $sheet->setCellValue('A1', 'Название товара');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E7FF');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Misol satrlari
        $examples = [
            'iPhone 17 Pro 256GB Black',
            'iPhone 17 Pro 512GB Blue',
            'MacBook Pro 14" M4 16GB 512GB',
            'AirPods Pro 2 USB-C',
            'Samsung Galaxy S25 Ultra 512GB',
            'Чехол силиконовый iPhone 17 Pro',
            'Зарядка USB-C 20W',
            'Кабель Lightning 1m',
        ];
        foreach ($examples as $i => $name) {
            $sheet->setCellValue('A' . ($i + 2), $name);
        }

        $sheet->getColumnDimension('A')->setWidth(40);

        // Eslatma sahifasi
        $infoSheet = $spreadsheet->createSheet();
        $infoSheet->setTitle('Инструкция');
        $instructions = [
            ['Импорт продуктов — инструкция'],
            [''],
            ['1. Заполните только колонку A — название товара (одно на строку).'],
            ['2. Первая строка должна содержать заголовок (будет автоматически пропущена).'],
            ['3. Тип (Serial / Bulk) и категорию выбирайте в форме импорта.'],
            ['4. Дубликаты и уже существующие товары пропускаются автоматически.'],
            ['5. Максимум 5000 строк за один импорт, максимальный размер файла 3 МБ.'],
            [''],
            ['Поддерживаемые форматы файлов: .xlsx, .csv, .txt'],
        ];
        foreach ($instructions as $i => $row) {
            $infoSheet->setCellValue('A' . ($i + 1), $row[0]);
        }
        $infoSheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $infoSheet->getColumnDimension('A')->setWidth(70);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

}
