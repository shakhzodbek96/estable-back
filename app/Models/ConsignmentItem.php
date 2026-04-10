<?php

namespace App\Models;

use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentItem extends Model
{
    protected $fillable = [
        'consignment_id',
        'item_type',
        'inventory_id',
        'accessory_id',
        'quantity',
        'sold_quantity',
        'returned_quantity',
        'agreed_price',
        'sold_at',
        'returned_at',
        'sale_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'agreed_price' => 'decimal:2',
            'sold_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    protected function computedStatus(): Attribute
    {
        return Attribute::get(function () {
            if ($this->sold_quantity == $this->quantity) return 'sold';
            if ($this->returned_quantity == $this->quantity) return 'returned';
            if ($this->sold_quantity + $this->returned_quantity == $this->quantity) return 'completed';
            if ($this->sold_quantity > 0 || $this->returned_quantity > 0) return 'partial';
            return 'pending';
        });
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function accessory(): BelongsTo
    {
        return $this->belongsTo(Accessory::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
