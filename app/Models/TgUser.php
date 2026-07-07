<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Telegram obunachi — chat_id ↔ tizim entity bog'lanishi (tenant jadval).
 *
 * `model` string orqali polimorf: 'user' | 'customer' | 'investor'.
 * $connection belgilanmaydi — tenant kontekstida avto tenant DB'da ishlaydi.
 */
class TgUser extends Model
{
    protected $fillable = [
        'model',
        'model_id',
        'chat_id',
        'name',
        'username',
        'type',
    ];

    /** model string → Eloquent klass xaritasi (yangi tur qo'shish shu yerda). */
    public const MODEL_MAP = [
        'user' => User::class,
        'customer' => Customer::class,
        'investor' => Investor::class,
    ];

    /** Ruxsat etilgan model turlari (validatsiya uchun). */
    public static function allowedModels(): array
    {
        return array_keys(self::MODEL_MAP);
    }

    /**
     * Bog'langan entity instance'ini qaytaradi (topilmasa null).
     */
    public function entity(): ?Model
    {
        $class = self::MODEL_MAP[$this->model] ?? null;
        if (! $class || ! $this->model_id) {
            return null;
        }

        return $class::query()->find($this->model_id);
    }
}
