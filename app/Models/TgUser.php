<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Yagona bot bilan aloqada bo'lgan chat (MARKAZIY reestr).
 *
 * $connection='pgsql' — central DB. Markaziy admin bu yerdan bloklaydi yoki
 * guruh/kanaldan chiqadi.
 */
class TgUser extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'chat_id',
        'name',
        'username',
        'type',
        'status',
    ];

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
