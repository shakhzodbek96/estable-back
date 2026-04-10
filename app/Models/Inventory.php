<?php

namespace App\Models;

use App\Enums\InventoryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    protected $fillable = [
        'product_id',
        'serial_number',
        'extra_serial_number',
        'purchase_price',
        'extra_cost',
        'selling_price',
        'sold_price',
        'sold_at',
        'status',
        'has_box',
        'notes',
        'state',
        'consignment_item_id',
        'shop_id',
        'investor_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'extra_cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'sold_price' => 'decimal:2',
            'sold_at' => 'datetime',
            'has_box' => 'boolean',
            'status' => InventoryStatus::class,
        ];
    }

    // Scopes

    public function scopeOwn(Builder $query): Builder
    {
        return $query->whereNull('consignment_item_id');
    }

    public function scopeConsigned(Builder $query): Builder
    {
        return $query->whereNotNull('consignment_item_id');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', InventoryStatus::InStock);
    }

    // Accessors

    protected function totalCost(): Attribute
    {
        return Attribute::get(fn () => (float) ($this->purchase_price + $this->extra_cost));
    }

    protected function isConsigned(): Attribute
    {
        return Attribute::get(fn () => !is_null($this->consignment_item_id));
    }

    // Relationships

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function consignmentItem(): BelongsTo
    {
        return $this->belongsTo(ConsignmentItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function repairCosts(): HasMany
    {
        return $this->hasMany(RepairCost::class);
    }
}
