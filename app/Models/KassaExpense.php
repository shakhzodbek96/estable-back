<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KassaExpense extends Model
{
    // Tenant model — $connection belgilamaymiz

    protected $fillable = [
        'shop_id', 'shift_id', 'type', 'method', 'currency',
        'amount', 'rate', 'status', 'transaction_id',
        'comment', 'details', 'created_by', 'checked_by', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'currency' => Currency::class,
            'status' => ExpenseStatus::class,
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
