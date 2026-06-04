<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
