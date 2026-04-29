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
 * Format:
 *   - Ustun A: Штрих-код (majburiy)
 *   - Ustun B: Количество (majburiy, > 0)
 *   - Ustun C: Закупочная цена (majburiy)
 *   - Ustun D: Цена продажи (majburiy)
 *   - Ustun E: Оптовая цена (ixtiyoriy)
 *   - Ustun F: Заметка (ixtiyoriy)
 */
class AccessoryImportService
{
    private const HEADER_KEYWORDS = [
        'barcode', 'штрих-код', 'штрихкод', 'штрих код', 'код', 'shtrix', 'shtrix-kod',
    ];

    public function __construct(
        private SpreadsheetParser $parser,
    ) {}

    /**
     * Fayldan partiyalar ro'yxatini chiqarib oladi.
     *
     * @return Collection<int, array{barcode: string, quantity: int, purchase_price: float, sell_price: float, wholesale_price: ?float, notes: ?string}>
     */
    public function extractBatches(UploadedFile $file): Collection
    {
        $rows = $this->parser->parseRows($file, maxColumns: 6);

        return collect($rows)
            ->map(fn ($row) => [
                'barcode' => mb_substr($this->parser->sanitizeCell($row[0] ?? ''), 0, 255),
                'quantity' => $this->parser->parseInt($row[1] ?? null),
                'purchase_price' => $this->parser->parseFloat($row[2] ?? null),
                'sell_price' => $this->parser->parseFloat($row[3] ?? null),
                'wholesale_price' => $row[4] !== null && $row[4] !== ''
                    ? $this->parser->parseFloat($row[4])
                    : null,
                'notes' => mb_substr($this->parser->sanitizeCell($row[5] ?? ''), 0, 1000) ?: null,
            ])
            ->filter(fn ($r) => $r['barcode'] !== ''
                && !in_array(mb_strtolower($r['barcode']), self::HEADER_KEYWORDS, true)
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

        $headers = ['Штрих-код', 'Количество', 'Закупочная (USD)', 'Цена продажи (USD)', 'Оптовая (USD)', 'Заметка'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue($col . '1', $h);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFEDD5');
        $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $examples = [
            ['8806094945829', 100, 4.50, 8.00, 7.00, 'Силиконовый чехол iPhone 17 Pro'],
            ['1234567890123', 50, 12.00, 20.00, 17.00, 'USB-C 20W зарядка'],
            ['9876543210987', 200, 1.20, 3.50, 2.80, 'Lightning кабель 1m'],
        ];
        foreach ($examples as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValueExplicit('A' . $r, (string) $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $r, $row[1]);
            $sheet->setCellValue('C' . $r, $row[2]);
            $sheet->setCellValue('D' . $r, $row[3]);
            $sheet->setCellValue('E' . $r, $row[4]);
            $sheet->setCellValue('F' . $r, $row[5]);
        }

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(13);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(35);

        // Инструкция
        $info = $spreadsheet->createSheet();
        $info->setTitle('Инструкция');
        $lines = [
            'Импорт партий аксессуаров — инструкция',
            '',
            'Заполните только последовательные колонки:',
            '  A — Штрих-код (обязательно, текстом)',
            '  B — Количество (целое число > 0)',
            '  C — Закупочная цена в USD',
            '  D — Цена продажи в USD',
            '  E — Оптовая цена в USD (опционально)',
            '  F — Заметка к партии (опционально)',
            '',
            'Первая строка — заголовок, пропускается автоматически.',
            '',
            'Товар, магазин, накладная и инвестор выбираются в форме',
            'перед загрузкой файла. Все импортированные строки будут',
            'привязаны к одной накладной и одному товару.',
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
