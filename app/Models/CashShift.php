<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    // Tenant model — $connection belgilamaymiz (tenant context'da avto)

    protected $fillable = [
        'shop_id', 'status', 'opened_by', 'opened_at',
        'closed_by', 'closed_at', 'opening_cash', 'counted_cash',
        'expected_cash', 'discrepancy', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'array',
            'counted_cash' => 'array',
            'expected_cash' => 'array',
            'discrepancy' => 'array',
        ];
    }

    /** Do'kon uchun ochiq smena (yoki null) */
    public static function openForShop(int $shopId): ?self
    {
        return static::where('shop_id', $shopId)
            ->where('status', ShiftStatus::Open)
            ->first();
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'shift_id');
    }
}
