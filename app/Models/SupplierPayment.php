<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Postavshik qarzini yopish uchun to'lov. supplier.balance ni kamaytiradi va
 * kassadan chiqim (transaction) yaratadi (3-bosqichda ulanadi).
 */
class SupplierPayment extends Model
{
    protected $fillable = [
        'supplier_id',
        'supply_batch_id',
        'amount',
        'currency',
        'rate',
        'transaction_id',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rate' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplyBatch::class, 'supply_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
