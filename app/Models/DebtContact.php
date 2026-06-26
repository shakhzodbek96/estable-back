<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Qarz daftari kontakti (odam) — loyihaning qolgan qismidan mustaqil.
 * Har kontaktda oldi-berdi yozuvlari (DebtEntry) bo'ladi; net balans
 * yozuvlardan valyuta bo'yicha hisoblanadi.
 *
 * $connection belgilanmaydi — tenant context'da avto 'tenant'ga o'tadi.
 */
class DebtContact extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'note',
        'created_by',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(DebtEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
