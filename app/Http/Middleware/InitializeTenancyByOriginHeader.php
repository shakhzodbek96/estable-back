<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

/**
 * Estable custom tenant resolver.
 *
 * Estable'da backend alohida domain'da (api.estable.uz) joylashgani uchun
 * oddiy subdomain/host routing ishlamaydi (request `Host` header'i doim
 * `api.estable.uz` bo'ladi). Shu sababli frontend yuborayotgan `Origin`
 * header'dan tenant domenini ajratib olamiz va shu orqali tenancy'ni
 * initsializatsiya qilamiz.
 *
 * Oqim:
 *   Origin: https://shop1.estable.uz
 *   → host = "shop1.estable.uz"
 *   → domains jadvalidan shu domain'ga biriktirilgan tenant topiladi
 *   → DB tenant_<id> ga ulanadi
 *
 * Zaxira:
 *   - `X-Tenant` custom header (Postman, server-to-server, Telegram bot)
 *   - `Referer` header (agar Origin yo'q bo'lsa)
 */
class InitializeTenancyByOriginHeader extends InitializeTenancyByDomain
{
    public function handle($request, Closure $next)
    {
        $host = $this->resolveHost($request);

        if ($host === null) {
            return response()->json([
                'message' => 'Tenant aniqlanmadi. Origin yoki X-Tenant header talab qilinadi.',
                'code' => 'tenant_origin_missing',
            ], 400);
        }

        return $this->initializeTenancy($request, $next, $host);
    }

    protected function resolveHost(Request $request): ?string
    {
        // 1. Origin header — brauzer har doim yuboradi (CORS)
        $origin = $request->headers->get('Origin');
        if ($origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        // 2. Custom header — server-to-server va bot uchun
        $xTenant = $request->headers->get('X-Tenant');
        if (is_string($xTenant) && $xTenant !== '') {
            return trim($xTenant);
        }

        // 3. Referer — zaxira
        $referer = $request->headers->get('Referer');
        if ($referer) {
            $host = parse_url($referer, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return null;
    }
}
