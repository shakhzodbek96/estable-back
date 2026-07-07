<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Telegram aktivatsiya kodi (tenant jadval).
 */
class TgOtp extends Model
{
    public const STATUS_NEW = 0;
    public const STATUS_USED = 1;

    protected $fillable = [
        'otp',
        'model',
        'model_id',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'status' => 'integer',
        ];
    }

    /**
     * Berilgan entity uchun yangi 6 xonali kod yaratadi (eski yangilari o'chiriladi).
     */
    public static function generateFor(string $model, int $modelId, int $ttlMinutes = 15): self
    {
        // Shu entity uchun ishlatilmagan eski kodlarni tozalaymiz (bitta aktiv kod qoladi)
        static::query()
            ->where('model', $model)
            ->where('model_id', $modelId)
            ->where('status', self::STATUS_NEW)
            ->delete();

        do {
            $code = (string) random_int(100000, 999999);
        } while (static::query()->where('otp', $code)->exists());

        return static::query()->create([
            'otp' => $code,
            'model' => $model,
            'model_id' => $modelId,
            'status' => self::STATUS_NEW,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    /** Kod hali amal qiladimi (ishlatilmagan va muddati o'tmagan). */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_NEW
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
