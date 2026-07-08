<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant'ga xos universal konfiguratsiya saqlovchi (key => json payload).
 *
 * Misol:
 *   Setting::getValue('pos_discount_limits')      // ['serial' => 20, 'accessory' => 15]
 *   Setting::setValue('pos_discount_limits', [...])
 */
class Setting extends Model
{
    // POS sotuvchi skidka limitlari (foizda): ['serial' => ?, 'accessory' => ?]
    public const POS_DISCOUNT_LIMITS = 'pos_discount_limits';

    // Chek (sotuv cheki) konfiguratsiyasi — qaysi maydonlar chiqishi, matnlar
    public const RECEIPT_CONFIG = 'receipt_config';

    // Public landing uchun do'kon ma'lumoti: ['name', 'about', 'phone', 'telegram', 'instagram']
    public const STORE_INFO = 'store_info';

    // Tenant Telegram maqsadli chat_id'lari (markaziy bot orqali):
    // ['notify_on_sale', 'sale_chat_id', 'daily_report_enabled', 'report_chat_id', 'send_hour']
    public const TELEGRAM_BOT_CONFIG = 'telegram_bot_config';

    // Tenant Telegram xabar shablonlari (foydalanuvchi tahrirlaydi): ['sale' => '...']
    public const TELEGRAM_TEMPLATES = 'telegram_templates';

    protected $fillable = [
        'key',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Kalit bo'yicha payload'ni qaytaradi (topilmasa — $default).
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->first()?->payload ?? $default;
    }

    /**
     * Kalit bo'yicha payload'ni saqlaydi (mavjud bo'lsa yangilaydi).
     */
    public static function setValue(string $key, mixed $payload): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['payload' => $payload],
        );
    }
}
