<?php

namespace App\Models;

use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'item_type',
        'inventory_id',
        'accessory_id',
        'quantity',
        'unit_price',
        'subtotal',
        'warranty_months',
        'warranty_note',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function accessory(): BelongsTo
    {
        return $this->belongsTo(Accessory::class);
    }
}
