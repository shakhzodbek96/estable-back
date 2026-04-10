<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $fillable = [
        'customer_id',
        'sale_date',
        'total_price',
        'payment_method',
        'investor_id',
        'shop_id',
        'sold_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'total_price' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(Return_::class);
    }
}
