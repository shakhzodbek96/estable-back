<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\HasImages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;
    use HasImages;

    protected $fillable = [
        'category_id',
        'type',
        'name',
        'min_stock',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'min_stock' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(Accessory::class);
    }
}
