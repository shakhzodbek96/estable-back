<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Telegram xabar shablonlari (tenant, foydalanuvchi tahrirlaydi).
 *
 * Shablonlar `{placeholder}` sintaksisi bilan. Yuborishda har safar DB'dan
 * O'QILMAYDI — effektiv shablonlar 3 SOATga keshlanadi (tenant-scoped),
 * shablon o'zgartirilганда `forgetCache()` bilan tozalanadi.
 */
class TelegramTemplateService
{
    public const CACHE_KEY = 'telegram_templates';

    /** Kesh muddati — 3 soat. */
    public const CACHE_TTL = 3 * 3600;

    /**
     * Shablon registri: turi → [label, default matn, placeholder'lar (kalit→izoh)].
     * Yangi tur shu yerga qo'shiladi.
     */
    public const REGISTRY = [
        'sale' => [
            'label' => 'Уведомление о продаже',
            'default' => "🧾 <b>Продажа №{sale_id}</b>\n"
                . "🕒 {datetime}\n"
                . "👤 Продавец: {seller}\n"
                . "🛒 Клиент: {customer}\n"
                . "📦 Товаров: {items_count}\n"
                . "💳 Оплата: {payment}\n"
                . "💰 Сумма: <b>{total}</b>",
            'placeholders' => [
                'sale_id' => 'Номер продажи',
                'datetime' => 'Дата и время',
                'seller' => 'Продавец',
                'customer' => 'Клиент',
                'items_count' => 'Кол-во товаров',
                'payment' => 'Способ оплаты',
                'total' => 'Сумма',
                'shop' => 'Магазин',
            ],
        ],
    ];

    /** Shablon max uzunligi (Telegram limiti 4096; zaxira bilan). */
    public const MAX_LENGTH = 2000;

    /**
     * Effektiv shablonlar (custom yoki default) — turi→matn. Keshlangan.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $custom = Setting::getValue(Setting::TELEGRAM_TEMPLATES, []);
            $custom = is_array($custom) ? $custom : [];

            $out = [];
            foreach (self::REGISTRY as $type => $meta) {
                $value = $custom[$type] ?? null;
                $out[$type] = is_string($value) && trim($value) !== '' ? $value : $meta['default'];
            }

            return $out;
        });
    }

    /** Bitta tur uchun effektiv shablon. */
    public function get(string $type): string
    {
        return $this->all()[$type] ?? (self::REGISTRY[$type]['default'] ?? '');
    }

    /**
     * Shablonni ma'lumot bilan render qiladi.
     * $data qiymatlari OLDINDAN xavfsiz bo'lishi kerak (dinamik matnlar esc'langan,
     * bloklar tayyor HTML). `{key}` → qiymat (literal str_replace — regex emas).
     *
     * @param  array<string, string>  $data
     */
    public function render(string $type, array $data): string
    {
        $tpl = $this->get($type);

        $search = [];
        $replace = [];
        foreach ($data as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }

        return str_replace($search, $replace, $tpl);
    }

    /**
     * Foydalanuvchi shablonlarini saqlaydi (default'ga teng yoki bo'sh — override saqlanmaydi).
     *
     * @param  array<string, string>  $templates  turi→matn
     */
    public function save(array $templates): void
    {
        $store = [];
        foreach (self::REGISTRY as $type => $meta) {
            $value = $templates[$type] ?? null;
            if (is_string($value) && trim($value) !== '' && trim($value) !== trim($meta['default'])) {
                $store[$type] = $value;
            }
        }

        Setting::setValue(Setting::TELEGRAM_TEMPLATES, $store);
        self::forgetCache();
    }

    /** Kesh'ni tozalash (shablon o'zgarganда). */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Frontend uchun: har tur bo'yicha joriy matn, default va placeholder'lar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forApi(): array
    {
        $effective = $this->all();

        $out = [];
        foreach (self::REGISTRY as $type => $meta) {
            $out[] = [
                'type' => $type,
                'label' => $meta['label'],
                'value' => $effective[$type] ?? $meta['default'],
                'default' => $meta['default'],
                'placeholders' => array_map(
                    static fn ($key, $desc) => ['key' => $key, 'desc' => $desc],
                    array_keys($meta['placeholders']),
                    array_values($meta['placeholders']),
                ),
            ];
        }

        return $out;
    }
}
