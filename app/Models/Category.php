<?php

namespace App\Models;

use App\Support\TenantMedia;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image',
        'icon',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url'];

    /**
     * Muqova rasmning to'liq URL'i (S3). `image` ustuni S3 kalitini saqlaydi.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => TenantMedia::url($this->image));
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
