<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DebtEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qarz daftari yozuvi — bitta oldi-berdi.
 *
 * type=credit → menga qarzdor (+), type=debit → men qarzdorman (−).
 */
class DebtEntry extends Model
{
    protected $fillable = [
        'debt_contact_id',
        'type',
        'amount',
        'currency',
        'comment',
        'entry_date',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => DebtEntryType::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'entry_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(DebtContact::class, 'debt_contact_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
