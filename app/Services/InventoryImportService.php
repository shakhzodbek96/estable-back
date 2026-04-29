<?php

namespace App\Services;

use App\Services\Import\SpreadsheetParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Serial (IMEI) inventarini fayldan import qilish — har qatorda alohida tovar.
 *
 * Format (9 ustun):
 *   A — product (nom): tizimda mavjud bo'lishi kerak (yoki auto_create=true bilan yaratiladi)
 *   B — purchase: zakup narx (USD)
 *   C — price: sotuv narxi (selling_price)
 *   D — retail: optom narx (wholesale_price), ixtiyoriy
 *   E — condition: new | used (default: new)
 *   F — box: yes | no | true | false | 1 | 0 (default: yes)
 *   G — imei: serial_number (majburiy)
 *   H — imei 2: extra_serial_number (ixtiyoriy)
 *   I — note: notes (ixtiyoriy)
 */
class InventoryImportService
{
    private const HEADER_KEYWORDS = [
        'product', 'товар', 'tovar', 'наименование', 'имя',
    ];

    public function __construct(
        private SpreadsheetParser $parser,
    ) {}

    /**
     * Fayldan rich qatorlarni o'qib chiqadi.
     *
     * @return Collection<int, array{product_name: string, purchase: float, price: float, retail: ?float, condition: string, has_box: bool, imei: string, imei2: ?string, note: ?string}>
     */
    public function extractRichRows(UploadedFile $file): Collection
    {
        $rows = $this->parser->parseRows($file, maxColumns: 9);

        return collect($rows)
            ->map(fn ($row) => [
                'product_name' => mb_substr($this->parser->sanitizeCell($row[0] ?? ''), 0, 255),
                'purchase' => $this->parser->parseFloat($row[1] ?? null),
                'price' => $this->parser->parseFloat($row[2] ?? null),
                'retail' => ($row[3] !== null && $row[3] !== '')
                    ? $this->parser->parseFloat($row[3])
                    : null,
                'condition' => $this->normalizeCondition($row[4] ?? null),
                'has_box' => $this->normalizeBool($row[5] ?? null, default: true),
                'imei' => mb_substr($this->parser->sanitizeCell($row[6] ?? ''), 0, 255),
                'imei2' => mb_substr($this->parser->sanitizeCell($row[7] ?? ''), 0, 255) ?: null,
                'note' => mb_substr($this->parser->sanitizeCell($row[8] ?? ''), 0, 1000) ?: null,
            ])
            ->filter(fn ($r) => $r['imei'] !== ''
                && $r['product_name'] !== ''
                // Sarlavha satrini avtomatik o'tkazib yuborish
                && !in_array(mb_strtolower($r['imei']), ['imei', 'imei 1', 'serial'], true)
                && !in_array(mb_strtolower($r['product_name']), self::HEADER_KEYWORDS, true))
            ->unique('imei')
            ->values();
    }

    /** condition normalize: new/used, ya'ni har qanday boshqa qiymat → 'new' */
    private function normalizeCondition(mixed $raw): string
    {
        $v = mb_strtolower((string) ($raw ?? ''));
        if (in_array($v, ['used', 'б/у', 'bu', 'old', 'second', 'secondhand'], true)) return 'used';
        return 'new';
    }

    /**
     * Bool normalize:
     *   true: yes, true, 1, +, да
     *   false: no, false, 0, -, нет
     *   bo'sh/noma'lum: $default
     */
    private function normalizeBool(mixed $raw, bool $default): bool
    {
        if ($raw === null || $raw === '') return $default;
        if (is_bool($raw)) return $raw;
        $v = mb_strtolower(trim((string) $raw));
        if (in_array($v, ['yes', 'y', 'true', '1', '+', 'да'], true)) return true;
        if (in_array($v, ['no', 'n', 'false', '0', '-', 'нет', 'нет коробки'], true)) return false;
        return $default;
    }

    /**
     * Misol XLSX shabloni — 9-ustunli rich format.
     */
    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Inventory');

        $headers = ['product', 'purchase', 'price', 'retail', 'condition', 'box', 'imei', 'imei 2', 'note'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue($col . '1', $h);
        }
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E7FF');
        $sheet->getStyle('A1:I1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $examples = [
            ['Macbook Air 13 inch M2 8/256GB',  560,  700,  670, 'used', 'no',  'J71732FYWK',   '', '1'],
            ['Macbook Air 13 inch M4 16/512GB', 930,  1000, 990, 'used', 'no',  'GJ9GQ0H1XC',   '', '1'],
            ['Macbook Pro 14 inch M3 Pro',      1220, 1320, 1300, 'used', 'yes', 'X0FN4VV77G', '', '0.97'],
            ['iPhone 17 Pro 256GB Black',       950,  1100, 1050, 'new',  'yes', '350123456789012', '350123456789013', ''],
        ];
        foreach ($examples as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValue('A' . $r, $row[0]);
            $sheet->setCellValue('B' . $r, $row[1]);
            $sheet->setCellValue('C' . $r, $row[2]);
            $sheet->setCellValue('D' . $r, $row[3]);
            $sheet->setCellValue('E' . $r, $row[4]);
            $sheet->setCellValue('F' . $r, $row[5]);
            // IMEI'ni TEXT sifatida saqlash — uzun raqamlar scientific notation'ga aylanmasin
            $sheet->setCellValueExplicit('G' . $r, (string) $row[6], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            if ($row[7]) $sheet->setCellValueExplicit('H' . $r, (string) $row[7], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            if ($row[8] !== '') $sheet->setCellValue('I' . $r, $row[8]);
        }

        $widths = ['A' => 38, 'B' => 12, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 8, 'G' => 22, 'H' => 22, 'I' => 12];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Инструкция
        $info = $spreadsheet->createSheet();
        $info->setTitle('Инструкция');
        $lines = [
            'Импорт серийных товаров — инструкция',
            '',
            'Колонки (английские заголовки в первой строке — обязательны):',
            '  A — product   — название товара (должно совпадать с системой, иначе будет создан)',
            '  B — purchase  — закупочная цена в USD',
            '  C — price     — цена продажи (розничная, selling_price)',
            '  D — retail    — оптовая цена в USD (wholesale_price), опционально',
            '  E — condition — состояние: new / used',
            '  F — box       — коробка: yes / no',
            '  G — imei      — IMEI / серийный номер (обязательно, уникально)',
            '  H — imei 2    — второй IMEI (опционально)',
            '  I — note      — заметка (опционально)',
            '',
            'Магазин и инвестор выбираются в форме перед загрузкой файла.',
            'Если товар (product) не найден в системе и установлена опция',
            '"Создавать недостающие товары", он будет создан автоматически',
            'с типом Serial и без категории.',
            '',
            'Дубликаты IMEI и уже существующие в базе пропускаются.',
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
