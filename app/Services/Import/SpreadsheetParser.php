<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * XLSX/CSV/TXT fayllarini xavfsiz o'qib, satr-ustun matrisaga aylantiruvchi yagona xizmat.
 *
 * Xavfsizlik:
 *   - Magic bytes orqali fayl turini aniqlash (kengaytmaga ishonilmaydi)
 *   - .xls (OLE) qabul qilinmaydi (eski format makros risklari)
 *   - PhpSpreadsheet read-only rejimda (formula evaluation o'chiq → CSV/XLSX injection xavfsiz)
 *   - Maks satr cheklovi (DoS oldini olish)
 *   - Bo'sh yacheykalar tashlanadi
 */
class SpreadsheetParser
{
    public const DEFAULT_MAX_ROWS = 5000;

    /**
     * Faylni o'qib, satr-ustun massivini qaytaradi.
     *
     * @return array<int, array<int, string>> Har bir satr — birinchi $maxColumns ustunining matn qiymatlari
     */
    public function parseRows(UploadedFile $file, int $maxColumns = 1, int $maxRows = self::DEFAULT_MAX_ROWS): array
    {
        $kind = $this->detectFileKind($file);

        return match ($kind) {
            'xlsx' => $this->readXlsx($file, $maxColumns, $maxRows),
            'csv', 'txt' => $this->readDelimited($file, $maxColumns, $maxRows),
            default => throw new \InvalidArgumentException("Неподдерживаемый формат файла"),
        };
    }

    /**
     * Yacheyka qiymatini tozalash:
     *   - control belgilarni olib tashlash
     *   - bo'shliq va qo'shtirnoqlarni trim qilish
     *   - CSV injection oldi (= + - @ bilan boshlanishlar zararsizlantiriladi)
     */
    public function sanitizeCell(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value, " \t\"'");

        if (preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = ltrim($value, "=+-@\t\r");
        }

        return $value;
    }

    /**
     * Foydalanuvchi yuborgan numerik qiymatni xavfsiz floatga aylantiradi.
     * Vergul (1,5) → nuqta (1.5) avtomatik konversiya. Aks holda 0.
     */
    public function parseFloat(mixed $value): float
    {
        if ($value === null || $value === '') return 0.0;
        if (is_numeric($value)) return (float) $value;
        if (is_string($value)) {
            $cleaned = str_replace([',', ' '], ['.', ''], $value);
            return is_numeric($cleaned) ? (float) $cleaned : 0.0;
        }
        return 0.0;
    }

    public function parseInt(mixed $value): int
    {
        if ($value === null || $value === '') return 0;
        if (is_numeric($value)) return (int) $value;
        return 0;
    }

    // ─── Internal: format detection ──────────────────────────────────

    private function detectFileKind(UploadedFile $file): string
    {
        $handle = fopen($file->path(), 'rb');
        if (!$handle) {
            throw new \RuntimeException("Не удалось прочитать файл");
        }
        $head = fread($handle, 8) ?: '';
        fclose($handle);

        if (str_starts_with($head, "PK\x03\x04")) {
            return 'xlsx';
        }

        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new \InvalidArgumentException(
                "Старый формат .xls не поддерживается. Сохраните как .xlsx или .csv."
            );
        }

        $extension = strtolower($file->getClientOriginalExtension());
        return $extension === 'csv' ? 'csv' : 'txt';
    }

    // ─── Internal: readers ──────────────────────────────────────────

    private function readXlsx(UploadedFile $file, int $maxColumns, int $maxRows): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        // ★ DIQQAT: setLoadSheetsOnly() integer indeksni qabul qilmaydi (faqat sheet nomlari).
        // Sheet nomi oldindan noma'lum bo'lgani uchun, barcha sheetlarni yuklaymiz va
        // keyin birinchisini olamiz — read-only rejimda bu xotira jihatidan arzon.

        $spreadsheet = $reader->load($file->path());
        $sheet = $spreadsheet->getSheet(0); // birinchi sheet (indeks bilan)

        $rows = [];
        $highestRow = min($sheet->getHighestRow(), $maxRows);

        for ($r = 1; $r <= $highestRow; $r++) {
            $row = [];
            $hasData = false;
            for ($c = 1; $c <= $maxColumns; $c++) {
                $value = $sheet->getCell([$c, $r])->getValue();
                $row[] = $value;
                if ($value !== null && $value !== '') $hasData = true;
            }
            if ($hasData) $rows[] = $row;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    private function readDelimited(UploadedFile $file, int $maxColumns, int $maxRows): array
    {
        $reader = new CsvReader();
        $reader->setReadDataOnly(true);
        $reader->setEnclosure('"');
        $reader->setInputEncoding('UTF-8');

        $sample = file_get_contents($file->path(), false, null, 0, 4096) ?: '';
        $sample = preg_replace('/^\xEF\xBB\xBF/', '', $sample);

        $separator = ',';
        if (substr_count($sample, ';') > substr_count($sample, ',')) {
            $separator = ';';
        } elseif (substr_count($sample, "\t") > 0 && substr_count($sample, ',') === 0) {
            $separator = "\t";
        }
        $reader->setDelimiter($separator);

        $spreadsheet = $reader->load($file->path());
        $sheet = $spreadsheet->getSheet(0);

        $rows = [];
        $highestRow = min($sheet->getHighestRow(), $maxRows);

        for ($r = 1; $r <= $highestRow; $r++) {
            $row = [];
            $hasData = false;
            for ($c = 1; $c <= $maxColumns; $c++) {
                $value = $sheet->getCell([$c, $r])->getValue();
                $row[] = $value;
                if ($value !== null && $value !== '') $hasData = true;
            }
            if ($hasData) $rows[] = $row;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }
}
