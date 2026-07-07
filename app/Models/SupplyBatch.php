<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Партия — bitta mol kirimi. Manba: doimiy postavshik (supplier_id) yoki
 * kо'chadagi odam (supplier_name matn).
 */
class SupplyBatch extends Model
{
    protected $fillable = [
        'supplier_id',
        'supplier_name',
        'invoice_number',
        'batch_date',
        'payment_mode',
        'total_cost',
        'notes',
        'shop_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'total_cost' => 'decimal:2',
        ];
    }

    /** Ko'rsatiladigan manba nomi: postavshik nomi yoki walk-in matn. */
    protected function sourceName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->supplier?->name ?? $this->supplier_name ?? '—',
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(Accessory::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
