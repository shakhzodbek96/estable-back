<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tenant faolligini kuzatish — har autentifikatsiyalangan tenant so'rovida
 * central `tenants.last_seen_at` ustunini yangilaydi. Bu adminkada tenantlar
 * platformadan foydalanayotgan-yotmaganini bilish uchun.
 *
 * MUHIM: bu middleware InitializeTenancyByOriginHeader'dan KEYIN turishi kerak
 * (tenant() aniqlangan bo'lishi uchun).
 *
 * Yozuv `$next()`dan KEYIN bajariladi — shu paytda ichki `auth:sanctum`
 * foydalanuvchini aniqlab bo'lgan bo'ladi, shuning uchun faqat haqiqiy
 * (login qilgan) foydalanuvchi faolligini hisobga olamiz. Public katalog
 * so'rovlari yoki muvaffaqiyatsiz login urinishlari last_seen'ni ko'tarmaydi.
 *
 * Har so'rovda DB'ga yozmaslik uchun tenantga xos cache kaliti orqali
 * throttle qilinadi (THROTTLE_MINUTES). Kalitga tenant id qo'shilgan —
 * $next()dan keyin tenancy central kontekstga qaytgan bo'lishi mumkin,
 * shuning uchun cache izolyatsiyasiga tayanmaymiz.
 */
class TrackTenantActivity
{
    private const THROTTLE_MINUTES = 5;

    public function handle(Request $request, Closure $next)
    {
        // Tenant id'ni oldindan olamiz: $next()dan keyin tenancy central
        // kontekstga qaytarilgan bo'lishi mumkin.
        $tenantId = tenant('id');

        $response = $next($request);

        if ($tenantId !== null && $request->user() !== null) {
            $this->touch((string) $tenantId);
        }

        return $response;
    }

    private function touch(string $tenantId): void
    {
        try {
            // Cache::add kalit mavjud bo'lmaganidagina true qaytaradi — shu
            // bilan har THROTTLE_MINUTES daqiqada bir marta DB yozuvini
            // kafolatlaymiz. Kalit tenant id bilan — tenantlararo to'qnashmaydi.
            $throttleKey = "_tenant_last_seen_touch:{$tenantId}";

            if (! Cache::add($throttleKey, 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
                return;
            }

            DB::connection('pgsql')
                ->table('tenants')
                ->where('id', $tenantId)
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable $e) {
            // Faollik kuzatuvi hech qachon asosiy so'rovni buzmasligi kerak.
        }
    }
}
