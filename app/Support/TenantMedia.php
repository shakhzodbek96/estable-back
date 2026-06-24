<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tenant-scoped S3 media helper.
 *
 * Fayllar S3 (Timeweb) bucket ichida quyidagi struktura bilan saqlanadi:
 *
 *   <tenant-id>/<model>/<uuid>.<ext>
 *
 * Misol:  macshop/shop/9f1c....webp
 *
 * - <tenant-id> = joriy tenant subdomeni (tenant('id'), masalan "macshop").
 * - <model>     = rasm tegishli model nomi (snake_case, masalan "shop").
 *
 * MUHIM: bu yerda stancl FilesystemTenancyBootstrapper'ning 's3' suffixingiga
 * tayanmaymiz (u 'tenant_' prefiks qo'shadi). To'liq kalit DB'da saqlanadi,
 * shu sababli url()/delete() tenant kontekstidan tashqarida ham to'g'ri ishlaydi.
 */
class TenantMedia
{
    private const DISK = 's3';

    /**
     * Faylni yuklaydi va saqlangan to'liq kalitni qaytaradi.
     * Eski kalit berilsa (almashtirish), u o'chiriladi.
     *
     * @param  Model|string  $owner  Model instance (nomidan folder olinadi) yoki tayyor folder nomi.
     */
    public static function store(UploadedFile $file, Model|string $owner, ?string $oldPath = null): string
    {
        $dir = self::tenantId() . '/' . self::folder($owner);
        $name = (string) Str::uuid() . '.' . self::extension($file);

        $path = $file->storeAs($dir, $name, self::DISK);

        if ($oldPath && $oldPath !== $path) {
            self::delete($oldPath);
        }

        return $path;
    }

    /**
     * Kalit bo'yicha faylni o'chiradi (mavjud bo'lmasa — jim o'tadi).
     */
    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Kalitdan to'liq public URL.
     */
    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk(self::DISK)->url($path) : null;
    }

    private static function folder(Model|string $owner): string
    {
        $base = $owner instanceof Model ? class_basename($owner) : $owner;

        return Str::snake($base);
    }

    private static function extension(UploadedFile $file): string
    {
        return $file->extension() ?: $file->getClientOriginalExtension();
    }

    private static function tenantId(): string
    {
        $id = tenant('id');

        if (! $id) {
            throw new \RuntimeException('TenantMedia: tenant konteksti aniqlanmagan.');
        }

        return (string) $id;
    }
}
