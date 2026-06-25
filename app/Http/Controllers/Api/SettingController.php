<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /**
     * POS skidka limitlari (sotuvchi necha % gacha skidka qila oladi).
     *
     * Javob: { "serial": number|null, "accessory": number|null }
     *   - serial    — donalik (IMEI) tovarlar uchun maksimal skidka foizi
     *   - accessory — aksessuarlar uchun maksimal skidka foizi
     *   - null      — cheklov yo'q (istalgan narxda sotish mumkin)
     */
    public function discountLimits(): JsonResponse
    {
        return response()->json($this->normalizeLimits(
            Setting::getValue(Setting::POS_DISCOUNT_LIMITS, [])
        ));
    }

    public function updateDiscountLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
            'accessory' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
        ], [], [
            'serial' => 'Скидка (донные/IMEI)',
            'accessory' => 'Скидка (аксессуары)',
        ]);

        $payload = [
            'serial' => $data['serial'] !== null ? (float) $data['serial'] : null,
            'accessory' => $data['accessory'] !== null ? (float) $data['accessory'] : null,
        ];

        Setting::setValue(Setting::POS_DISCOUNT_LIMITS, $payload);

        return response()->json($this->normalizeLimits($payload));
    }

    /**
     * Chek (sotuv cheki) konfiguratsiyasi — qaysi maydonlar/matnlar chiqishi.
     * GET — barcha auth foydalanuvchilar (POS ham o'qiydi).
     */
    public function receiptConfig(): JsonResponse
    {
        return response()->json($this->normalizeReceiptConfig(
            Setting::getValue(Setting::RECEIPT_CONFIG, [])
        ));
    }

    /**
     * Chek konfiguratsiyasini yangilash (faqat admin).
     */
    public function updateReceiptConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paper_width' => ['required', Rule::in([58, 80])],
            'show_store' => ['required', 'boolean'],
            'header_lines' => ['present', 'array', 'max:6'],
            'header_lines.*' => ['nullable', 'string', 'max:60'],
            'show_sale_number' => ['required', 'boolean'],
            'show_datetime' => ['required', 'boolean'],
            'show_seller' => ['required', 'boolean'],
            'show_customer' => ['required', 'boolean'],
            'show_serial' => ['required', 'boolean'],
            'show_payments' => ['required', 'boolean'],
            'warranty_enabled' => ['required', 'boolean'],
            'warranty_title' => ['nullable', 'string', 'max:40'],
            'footer_text' => ['nullable', 'string', 'max:200'],
        ]);

        $payload = $this->normalizeReceiptConfig($data);
        Setting::setValue(Setting::RECEIPT_CONFIG, $payload);

        return response()->json($payload);
    }

    /** Chek konfiguratsiyasi standart qiymatlari */
    private function defaultReceiptConfig(): array
    {
        return [
            'paper_width' => 58,
            'show_store' => true,
            'header_lines' => [],
            'show_sale_number' => true,
            'show_datetime' => true,
            'show_seller' => true,
            'show_customer' => true,
            'show_serial' => true,
            'show_payments' => true,
            'warranty_enabled' => true,
            'warranty_title' => 'ГАРАНТИЯ',
            'footer_text' => 'Спасибо за покупку!',
        ];
    }

    /** Payload'ni to'liq, to'g'ri tipdagi config shakliga keltiradi */
    private function normalizeReceiptConfig(mixed $payload): array
    {
        $d = $this->defaultReceiptConfig();
        $p = is_array($payload) ? $payload : [];

        $bool = static fn (string $k) => array_key_exists($k, $p) ? (bool) $p[$k] : $d[$k];

        $lines = array_values(array_filter(
            array_map(static fn ($l) => is_string($l) ? trim($l) : '', $p['header_lines'] ?? $d['header_lines']),
            static fn ($l) => $l !== ''
        ));

        $pw = (int) ($p['paper_width'] ?? $d['paper_width']);
        $wt = $p['warranty_title'] ?? null;
        $ft = $p['footer_text'] ?? null;

        return [
            'paper_width' => in_array($pw, [58, 80], true) ? $pw : 58,
            'show_store' => $bool('show_store'),
            'header_lines' => array_slice($lines, 0, 6),
            'show_sale_number' => $bool('show_sale_number'),
            'show_datetime' => $bool('show_datetime'),
            'show_seller' => $bool('show_seller'),
            'show_customer' => $bool('show_customer'),
            'show_serial' => $bool('show_serial'),
            'show_payments' => $bool('show_payments'),
            'warranty_enabled' => $bool('warranty_enabled'),
            'warranty_title' => is_string($wt) && trim($wt) !== '' ? trim($wt) : $d['warranty_title'],
            'footer_text' => is_string($ft) ? trim($ft) : $d['footer_text'],
        ];
    }

    /**
     * Payload'ni doimo to'liq, tipi to'g'ri shaklga keltiradi.
     *
     * @return array{serial: float|null, accessory: float|null}
     */
    private function normalizeLimits(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];

        $clamp = static function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            return max(0.0, min(100.0, (float) $v));
        };

        return [
            'serial' => $clamp($payload['serial'] ?? null),
            'accessory' => $clamp($payload['accessory'] ?? null),
        ];
    }
}
