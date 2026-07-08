<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TelegramConfig;
use App\Services\TelegramReportService;
use App\Services\TelegramTemplateService;
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

    /**
     * Public landing uchun do'kon ma'lumoti (about + aloqa).
     * GET — admin sozlamalar formasi o'qiydi (public landing /catalog/store orqali oladi).
     */
    public function storeInfo(): JsonResponse
    {
        return response()->json($this->normalizeStoreInfo(
            Setting::getValue(Setting::STORE_INFO, [])
        ));
    }

    /** Do'kon ma'lumotini yangilash (faqat admin). */
    public function updateStoreInfo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'about' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'telegram' => ['nullable', 'string', 'max:100'],
            'instagram' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = $this->normalizeStoreInfo($data);
        Setting::setValue(Setting::STORE_INFO, $payload);

        return response()->json($payload);
    }

    /**
     * Tenant Telegram sozlamasi (faqat admin o'qiydi).
     * Bot token MARKAZIY (admin panelda) — bu yerda faqat maqsadli chat_id'lar.
     */
    public function telegramConfig(TelegramReportService $service): JsonResponse
    {
        $cfg = $service->config();
        $central = TelegramConfig::current();

        return response()->json([
            'notify_on_sale' => $cfg['notify_on_sale'],
            'sale_chat_id' => $cfg['sale_chat_id'],
            'daily_report_enabled' => $cfg['daily_report_enabled'],
            'report_chat_id' => $cfg['report_chat_id'],
            'send_hour' => $cfg['send_hour'],
            // Markaziy bot holati — tenant chat_id qo'yishi mumkinligini belgilaydi
            'bot_configured' => $cfg['bot_token'] !== '',
            'bot_username' => $central?->bot_username,
        ]);
    }

    /**
     * Tenant Telegram sozlamasini yangilaydi (faqat admin).
     * ★ Shart: markaziy bot token set qilinmagan bo'lsa — chat_id qo'yib bo'lmaydi.
     */
    public function updateTelegramConfig(Request $request): JsonResponse
    {
        // Req 3: bot token bo'lmasa tenant chat_id qo'ya olmaydi
        if (TelegramConfig::activeToken() === '') {
            return response()->json([
                'message' => 'Telegram-бот ещё не настроен администратором. Обратитесь к администратору.',
            ], 422);
        }

        $data = $request->validate([
            'notify_on_sale' => ['required', 'boolean'],
            'sale_chat_id' => ['nullable', 'string', 'max:100'],
            'daily_report_enabled' => ['required', 'boolean'],
            'report_chat_id' => ['nullable', 'string', 'max:100'],
            'send_hour' => ['required', 'integer', 'min:0', 'max:23'],
        ], [], [
            'sale_chat_id' => 'Chat ID для продаж',
            'report_chat_id' => 'Chat ID для отчётов',
            'send_hour' => 'Час отправки',
        ]);

        $payload = [
            'notify_on_sale' => (bool) $data['notify_on_sale'],
            'sale_chat_id' => trim((string) ($data['sale_chat_id'] ?? '')),
            'daily_report_enabled' => (bool) $data['daily_report_enabled'],
            'report_chat_id' => trim((string) ($data['report_chat_id'] ?? '')),
            'send_hour' => (int) $data['send_hour'],
        ];

        Setting::setValue(Setting::TELEGRAM_BOT_CONFIG, $payload);
        TelegramReportService::forgetCache(); // kesh yangilanadi

        return response()->json($payload + [
            'bot_configured' => true,
            'bot_username' => TelegramConfig::current()?->bot_username,
        ]);
    }

    /** Telegram xabar shablonlari (joriy + default + placeholder'lar). */
    public function telegramTemplates(TelegramTemplateService $service): JsonResponse
    {
        return response()->json(['templates' => $service->forApi()]);
    }

    /** Shablonlarni saqlaydi (kesh avtomatik tozalanadi). */
    public function updateTelegramTemplates(Request $request, TelegramTemplateService $service): JsonResponse
    {
        $rules = [];
        foreach (array_keys(TelegramTemplateService::REGISTRY) as $type) {
            $rules[$type] = ['nullable', 'string', 'max:' . TelegramTemplateService::MAX_LENGTH];
        }

        $data = $request->validate($rules);
        $service->save($data);

        return response()->json(['templates' => $service->forApi()]);
    }

    /**
     * Hisobotni HOZIR yuboradi (sinov / qo'lda yuborish tugmasi).
     * Sinxron — foydalanuvchi darhol natijani ko'radi.
     */
    public function sendTelegramNow(TelegramReportService $service): JsonResponse
    {
        $result = $service->sendDailyReport();

        if ($result['ok'] ?? false) {
            return response()->json(['ok' => true, 'message' => 'Отчёт отправлен']);
        }

        if ($result['skipped'] ?? false) {
            return response()->json(['ok' => false, 'message' => 'Отправка отчётов выключена'], 422);
        }

        return response()->json(['ok' => false, 'message' => $result['error'] ?? 'Не удалось отправить отчёт'], 422);
    }

    /** Do'kon ma'lumoti standart qiymatlari */
    private function defaultStoreInfo(): array
    {
        return [
            'name' => '',
            'about' => '',
            'phone' => '',
            'telegram' => '',
            'instagram' => '',
        ];
    }

    /** Payload'ni to'liq, tipi to'g'ri do'kon-ma'lumot shakliga keltiradi */
    private function normalizeStoreInfo(mixed $payload): array
    {
        $d = $this->defaultStoreInfo();
        $p = is_array($payload) ? $payload : [];

        $str = static fn (string $k, int $max): string => isset($p[$k]) && is_string($p[$k])
            ? mb_substr(trim($p[$k]), 0, $max)
            : $d[$k];

        return [
            'name' => $str('name', 80),
            'about' => $str('about', 2000),
            'phone' => $str('phone', 40),
            'telegram' => $str('telegram', 100),
            'instagram' => $str('instagram', 100),
        ];
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
