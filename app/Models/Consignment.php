<?php

namespace App\Models;

use App\Enums\ConsignmentDirection;
use App\Enums\ConsignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consignment extends Model
{
    protected $fillable = [
        'partner_id',
        'direction',
        'start_date',
        'deadline',
        'status',
        'notes',
        'shop_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ConsignmentDirection::class,
            'status' => ConsignmentStatus::class,
            'start_date' => 'datetime',
            'deadline' => 'date',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConsignmentItem::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
