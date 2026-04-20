<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Fallback Tenant ID generator.
 *
 * Estable'da tenant ID (= subdomain = schema nomi) odatda CENTRAL ADMIN tomonidan
 * QO'LDA kiritiladi (`TenantController::store` da `slug` validation required).
 *
 * Bu generator faqat zahira — agar `Tenant::create()` ga `id` berilmasa, name'dan
 * slug hosil qilib qaytaradi. Productionda bu yo'lga kam-kam tushiladi (CLI yoki
 * seeder paytida), chunki UI har doim `id` ni manual beradi.
 *
 * Misollar:
 *   name = "Aziz Electronics"  →  id = "aziz-electronics"
 *   name = null                 →  id = "tenant"
 */
class TenantIdGenerator implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        $name = null;

        if (is_object($resource) && isset($resource->name)) {
            $name = (string) $resource->name;
        } elseif (is_array($resource) && isset($resource['name'])) {
            $name = (string) $resource['name'];
        }

        $slug = $name ? Str::slug($name, '-') : '';

        if ($slug === '' || preg_match('/^\d+$/', $slug)) {
            $slug = 'tenant';
        }

        // Postgres identifier max 63. "tenant_" prefix = 7 chars.
        if (mb_strlen($slug) > 55) {
            $slug = rtrim(mb_substr($slug, 0, 55), '-');
        }

        return $slug;
    }
}
