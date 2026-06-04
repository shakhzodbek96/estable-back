<?php

namespace App\Services;

use App\Services\Import\SpreadsheetParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Bulk aksessuar partiyalarini fayldan import qilish.
 *
 * Format (7 ustun):
 *   - Ustun A: Наименование (product nomi, majburiy — tizimda yo'q bo'lsa avto-yaratiladi)
 *   - Ustun B: Штрих-код (majburiy)
 *   - Ustun C: Количество (majburiy, > 0)
 *   - Ustun D: Закупочная цена (majburiy)
 *   - Ustun E: Цена продажи (majburiy)
 *   - Ustun F: Оптовая цена (ixtiyoriy)
 *   - Ustun G: Заметка (ixtiyoriy)
 */
class AccessoryImportService
{
    private const BARCODE_HEADER_KEYWORDS = [
        'barcode', 'штрих-код', 'штрихкод', 'штрих код', 'код', 'shtrix', 'shtrix-kod',
    ];

    private const PRODUCT_HEADER_KEYWORDS = [
        'product', 'товар', 'tovar', 'наименование', 'имя', 'название', 'nom',
    ];

    public function __construct(
        private SpreadsheetParser $parser,
    ) {}

    /**
     * Fayldan partiyalar ro'yxatini chiqarib oladi.
     *
     * @return Collection<int, array{product_name: string, barcode: string, quantity: int, purchase_price: float, sell_price: float, wholesale_price: ?float, notes: ?string}>
     */
    public function extractBatches(UploadedFile $file): Collection
    {
        $rows = $this->parser->parseRows($file, maxColumns: 7);

        return collect($rows)
            ->map(fn ($row) => [
                'product_name' => mb_substr($this->parser->sanitizeCell($row[0] ?? ''), 0, 255),
                'barcode' => mb_substr($this->parser->sanitizeCell($row[1] ?? ''), 0, 255),
                'quantity' => $this->parser->parseInt($row[2] ?? null),
                'purchase_price' => $this->parser->parseFloat($row[3] ?? null),
                'sell_price' => $this->parser->parseFloat($row[4] ?? null),
                'wholesale_price' => $row[5] !== null && $row[5] !== ''
                    ? $this->parser->parseFloat($row[5])
                    : null,
                'notes' => mb_substr($this->parser->sanitizeCell($row[6] ?? ''), 0, 1000) ?: null,
            ])
            ->filter(fn ($r) => $r['barcode'] !== ''
                && $r['product_name'] !== ''
                // Sarlavha satrini avtomatik o'tkazib yuborish
                && !in_array(mb_strtolower($r['barcode']), self::BARCODE_HEADER_KEYWORDS, true)
                && !in_array(mb_strtolower($r['product_name']), self::PRODUCT_HEADER_KEYWORDS, true)
                && $r['quantity'] > 0
                && $r['purchase_price'] >= 0
                && $r['sell_price'] >= 0)
            ->unique('barcode')
            ->values();
    }

    /**
     * Misol XLSX shabloni — Accessory (bulk) import uchun.
     */
    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Партии');

        $headers = ['Наименование', 'Штрих-код', 'Количество', 'Закупочная (USD)', 'Цена продажи (USD)', 'Оптовая (USD)', 'Заметка'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue($col . '1', $h);
        }
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1:G1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFEDD5');
        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $examples = [
            ['Силиконовый чехол iPhone 17 Pro', '8806094945829', 100, 4.50, 8.00, 7.00, 'Чёрный'],
            ['Зарядка USB-C 20W', '1234567890123', 50, 12.00, 20.00, 17.00, 'Оригинал'],
            ['Кабель Lightning 1m', '9876543210987', 200, 1.20, 3.50, 2.80, ''],
        ];
        foreach ($examples as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValue('A' . $r, $row[0]);
            $sheet->setCellValueExplicit('B' . $r, (string) $row[1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $r, $row[2]);
            $sheet->setCellValue('D' . $r, $row[3]);
            $sheet->setCellValue('E' . $r, $row[4]);
            $sheet->setCellValue('F' . $r, $row[5]);
            if ($row[6] !== '') $sheet->setCellValue('G' . $r, $row[6]);
        }

        $sheet->getColumnDimension('A')->setWidth(35);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(13);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(30);

        // Инструкция
        $info = $spreadsheet->createSheet();
        $info->setTitle('Инструкция');
        $lines = [
            'Импорт партий аксессуаров — инструкция',
            '',
            'Заполните только последовательные колонки:',
            '  A — Наименование товара (обязательно)',
            '  B — Штрих-код (обязательно, текстом)',
            '  C — Количество (целое число > 0)',
            '  D — Закупочная цена в USD',
            '  E — Цена продажи в USD',
            '  F — Оптовая цена в USD (опционально)',
            '  G — Заметка к партии (опционально)',
            '',
            'Первая строка — заголовок, пропускается автоматически.',
            '',
            'У каждой строки — свой товар (по наименованию).',
            'Если товар не найден в системе, он создаётся автоматически',
            'с типом Bulk (аксессуар) и без категории.',
            '',
            'Магазин, накладная и инвестор выбираются в форме перед',
            'загрузкой файла — они общие для всех строк.',
            '',
            'Максимум 5000 строк, размер файла — 3 МБ.',
            'Поддерживаемые форматы: .xlsx, .csv, .txt',
        ];
        foreach ($lines as $i => $line) {
            $info->setCellValue('A' . ($i + 1), $line);
        }
        $info->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $info->getColumnDimension('A')->setWidth(80);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }
}
