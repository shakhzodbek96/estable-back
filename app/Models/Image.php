<?php

namespace App\Models;

use App\Support\TenantMedia;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Image extends Model
{
    protected $fillable = [
        'path',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url'];

    protected $hidden = ['path'];

    protected static function booted(): void
    {
        // Model o'chirilganda S3'dagi faylni ham o'chiramiz.
        static::deleting(function (Image $image) {
            TenantMedia::delete($image->path);
        });
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn () => TenantMedia::url($this->path));
    }
}
