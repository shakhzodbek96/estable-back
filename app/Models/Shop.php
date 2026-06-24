<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\TenantMedia;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'image_path',
        'address',
        'yandex_maps_url',
        'google_maps_url',
        'working_hours',
        'created_by',
    ];

    protected $casts = [
        'working_hours' => 'array',
    ];

    protected $appends = ['image_url'];

    /**
     * Rasmning to'liq URL'i (S3). image_path tenant-prefiksli kalit.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(
            fn () => TenantMedia::url($this->image_path)
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(Accessory::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
