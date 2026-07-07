<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Markaziy (central) — yagona info-bot konfiguratsiyasi.
 *
 * $connection='pgsql' — tenant kontekstida ham CENTRAL DB'da ishlaydi
 * (bot token butun SaaS uchun yagona).
 */
class TelegramConfig extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'bot_token',
        'bot_username',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * So'rov davomida token bir marta o'qiladi (per-request memo).
     * Persistent cache ATAYIN ishlatilmaydi: u tenant-scoped bo'lib, markaziy
     * token o'zgarganда tenantlarga eskirib qolar edi. Bitta indekslangan
     * qatorni o'qish arzon; takror yuborishlarda esa memo DB'ni takrorlamaydi.
     */
    private static ?string $tokenMemo = null;

    /** Yagona konfiguratsiya qatori (mavjud bo'lmasa null). */
    public static function current(): ?self
    {
        return static::query()->first();
    }

    /** Faol bot token. Bot o'chirilган/token yo'q bo'lsa — ''. */
    public static function activeToken(): string
    {
        if (self::$tokenMemo !== null) {
            return self::$tokenMemo;
        }

        $c = static::query()->first();

        return self::$tokenMemo = $c && $c->is_active && $c->bot_token ? $c->bot_token : '';
    }

    /** Token o'zgarganda memo'ni tozalash. */
    public static function forgetCache(): void
    {
        self::$tokenMemo = null;
    }
}
