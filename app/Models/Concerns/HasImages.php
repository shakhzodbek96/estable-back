<?php

namespace App\Models\Concerns;

use App\Models\Image;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Polimorfik rasm galereyasi (Product, Accessory, ...).
 *
 * - images()        — barcha rasmlar (asosiy birinchi, keyin tartib/id bo'yicha)
 * - primaryImage()  — faqat asosiy (muqova) rasm
 *
 * Parent model o'chirilganda barcha rasmlar (+ S3 fayllar) tozalanadi.
 */
trait HasImages
{
    public static function bootHasImages(): void
    {
        static::deleting(function ($model) {
            // har bir Image o'chirilganda uning `deleting` eventi S3 faylni tozalaydi
            foreach ($model->images()->get() as $image) {
                $image->delete();
            }
        });
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function primaryImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')
            ->where('is_primary', true);
    }
}
