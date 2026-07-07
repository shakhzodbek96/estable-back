<?php

namespace App\Services;

use App\Enums\AttributeScope;
use App\Models\AttributeDefinition;
use App\Services\Import\SpreadsheetParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
 *
 * J+ — dinamik atribut ustunlari (ixtiyoriy): ustun sarlavhasi atribut NOMI bilan
 *      mos kelsa (masalan "Цвет", "Память"), o'sha ustundagi qiymatlar shu atributga
 *      yoziladi. Sarlavha nomi topilmasa — ustun e'tiborsiz qoladi.
 */
class InventoryImportService
{
    private const HEADER_KEYWORDS = [
        'product', 'товар', 'tovar', 'наименование', 'имя',
    ];

    /** Qat'iy (positional) ustunlar soni — undan keyingilari atribut ustunlari. */
    private const FIXED_COLUMNS = 9;

    /** Ko'pi bilan shuncha atribut ustuni o'qiladi (DoS oldini olish). */
    private const MAX_ATTR_COLUMNS = 30;

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
        // Aktiv serial-atributlari: normalizatsiya qilingan nom → ta'rif.
        $attrByName = AttributeDefinition::query()
            ->active()
            ->forScope(AttributeScope::Serial)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (AttributeDefinition $d) => $this->normalizeName($d->name));

        $maxCols = self::FIXED_COLUMNS + self::MAX_ATTR_COLUMNS;
        $rows = $this->parser->parseRows($file, maxColumns: $maxCols);

        // Sarlavha satridan atribut ustunlarini aniqlash: [ustun_indeksi => ta'rif].
        // Faqat birinchi satr sarlavha bo'lsa va nom atributga mos kelsa.
        $attrColumns = [];
        if (!empty($rows) && $attrByName->isNotEmpty() && $this->looksLikeHeader($rows[0])) {
            for ($c = self::FIXED_COLUMNS; $c < $maxCols; $c++) {
                $name = $this->normalizeName($this->parser->sanitizeCell((string) ($rows[0][$c] ?? '')));
                if ($name !== '' && $attrByName->has($name)) {
                    $attrColumns[$c] = $attrByName->get($name);
                }
            }
        }

        return collect($rows)
            ->map(function ($row) use ($attrColumns) {
                // Dinamik atributlar — [{id, value}] (bo'sh yacheykalar tashlanadi).
                $custom = [];
                foreach ($attrColumns as $c => $def) {
                    $raw = $this->parser->sanitizeCell((string) ($row[$c] ?? ''));
                    if ($raw !== '') {
                        $custom[] = ['id' => $def->id, 'value' => $raw];
                    }
                }

                return [
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
                    'custom_attributes' => $custom,
                ];
            })
            ->filter(fn ($r) => $r['imei'] !== ''
                && $r['product_name'] !== ''
                // Sarlavha satrini avtomatik o'tkazib yuborish
                && !in_array(mb_strtolower($r['imei']), ['imei', 'imei 1', 'serial'], true)
                && !in_array(mb_strtolower($r['product_name']), self::HEADER_KEYWORDS, true))
            ->unique('imei')
            ->values();
    }

    /** Birinchi satr sarlavha ekanini aniqlaydi (product/imei kalit so'zlari bo'yicha). */
    private function looksLikeHeader(array $row): bool
    {
        $a = mb_strtolower($this->parser->sanitizeCell((string) ($row[0] ?? '')));
        $g = mb_strtolower($this->parser->sanitizeCell((string) ($row[6] ?? '')));

        return in_array($a, self::HEADER_KEYWORDS, true)
            || in_array($g, ['imei', 'imei 1', 'imei1', 'serial'], true);
    }

    /** Atribut nomini solishtirish uchun normalize: trim + bitta bo'shliq + lowercase. */
    private function normalizeName(string $name): string
    {
        $n = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_strtolower($n);
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

        // Dinamik atribut ustunlari: aktiv serial atributlarining NOMLARI sarlavha bo'ladi.
        // Foydalanuvchi shu ustunlarga qiymat yozsa — import ularni atributga yozadi.
        $attrNames = AttributeDefinition::query()
            ->active()
            ->forScope(AttributeScope::Serial)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('name')
            ->all();

        $allHeaders = array_merge($headers, $attrNames);
        foreach ($allHeaders as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($allHeaders));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E7FF');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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
        // Atribut ustunlari kengligi
        foreach (array_keys($attrNames) as $i) {
            $col = Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS + $i + 1);
            $sheet->getColumnDimension($col)->setWidth(16);
        }

        // Инструкция
        $info = $spreadsheet->createSheet();
        $info->setTitle('Инструкция');
        $lines = [
            'Импорт серийных товаров — инструкция',
            '',
            'Колонки (английские заголовки в первой строке — обязательны):',
            '  A — product   — название товара (если нет в системе — создаётся автоматически)',
            '  B — purchase  — закупочная цена в USD',
            '  C — price     — цена продажи (розничная, selling_price)',
            '  D — retail    — оптовая цена в USD (wholesale_price), опционально',
            '  E — condition — состояние: new / used',
            '  F — box       — коробка: yes / no',
            '  G — imei      — IMEI / серийный номер (обязательно, уникально)',
            '  H — imei 2    — второй IMEI (опционально)',
            '  I — note      — заметка (опционально)',
            '',
            'Динамические атрибуты (опционально):',
            '  Добавьте колонки после «note», где ЗАГОЛОВОК = название атрибута',
            '  (например «Цвет», «Память»). Значения из этих колонок запишутся',
            '  в соответствующий атрибут товара. Заголовки атрибутов уже добавлены',
            '  в шаблон (если они настроены в разделе «Характеристики»).',
            '',
            'Магазин и инвестор выбираются в форме перед загрузкой файла.',
            'Если товар (product) не найден в системе, он создаётся',
            'автоматически с типом Serial и без категории.',
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
