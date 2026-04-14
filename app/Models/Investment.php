<?php

namespace App\Models;

use App\Enums\InvestmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Investment extends Model
{
    protected $fillable = [
        'investor_id',
        'transaction_id',
        'type',
        'is_credit',
        'amount',
        'rate',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvestmentType::class,
            'is_credit' => 'boolean',
            'amount' => 'decimal:2',
            'rate' => 'decimal:2',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
