<?php

namespace App\Services;

use App\Models\Image;
use App\Support\TenantMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Polimorfik rasm galereyasini boshqaradi (HasImages trait'li modellar uchun).
 */
class ImageService
{
    /**
     * Bir nechta faylni yuklaydi. Birinchi rasm (galereya bo'sh bo'lsa) avto-asosiy bo'ladi.
     *
     * @param  UploadedFile[]  $files
     * @return \Illuminate\Support\Collection<int, Image>
     */
    public function addMany(Model $owner, array $files)
    {
        return DB::transaction(function () use ($owner, $files) {
            $hasPrimary = $owner->images()->where('is_primary', true)->exists();
            $maxOrder = (int) $owner->images()->max('sort_order');

            $created = collect();

            foreach ($files as $file) {
                $path = TenantMedia::store($file, $owner);

                $image = $owner->images()->create([
                    'path' => $path,
                    'is_primary' => ! $hasPrimary, // birinchisi asosiy
                    'sort_order' => ++$maxOrder,
                ]);

                $hasPrimary = true;
                $created->push($image);
            }

            return $created;
        });
    }

    /**
     * Berilgan rasmni asosiy qiladi, qolganlarini asosiylikdan chiqaradi.
     */
    public function setPrimary(Model $owner, Image $image): void
    {
        DB::transaction(function () use ($owner, $image) {
            $owner->images()->where('is_primary', true)->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
        });
    }

    /**
     * Rasmni o'chiradi. Agar asosiy bo'lsa — qolganlaridan birini asosiy qiladi.
     */
    public function delete(Model $owner, Image $image): void
    {
        DB::transaction(function () use ($owner, $image) {
            $wasPrimary = $image->is_primary;
            $image->delete(); // model `deleting` eventi S3 faylni tozalaydi

            if ($wasPrimary) {
                $next = $owner->images()->orderBy('sort_order')->orderBy('id')->first();
                $next?->update(['is_primary' => true]);
            }
        });
    }
}
