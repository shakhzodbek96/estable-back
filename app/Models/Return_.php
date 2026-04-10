<?php

namespace App\Models;

use App\Enums\ItemCondition;
use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Return_ extends Model
{
    protected $table = 'returns';

    protected $fillable = [
        'sale_id',
        'sale_item_id',
        'customer_id',
        'reason',
        'reason_note',
        'return_type',
        'refund_amount',
        'refund_method',
        'new_sale_id',
        'price_difference',
        'item_condition',
        'transfers_to_shop',
        'status',
        'shop_id',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ReturnReason::class,
            'return_type' => ReturnType::class,
            'refund_amount' => 'decimal:2',
            'price_difference' => 'decimal:2',
            'item_condition' => ItemCondition::class,
            'transfers_to_shop' => 'boolean',
            'status' => ReturnStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function newSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'new_sale_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
