<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;

/**
 * Central endpoint himoyasi — request uchun autentifikatsiya qilingan
 * foydalanuvchi `AdminUser` instance bo'lishini majbur qiladi.
 *
 * Asosan xatolikdan saqlovchi qatlam: Sanctum kontekst asosida tenant user
 * token'lari central schema'dagi `personal_access_tokens` jadvalida
 * topilmaydi, shuning uchun bu middleware amalda kamdan-kam ishlaydi.
 * Ammo agar kelajakda biror konfiguratsiya o'zgarib tenant user token'i
 * central contextda qabul qilinib qolsa, bu middleware himoya qiladi.
 *
 * `auth:sanctum` middleware'idan KEYIN joylashtirilishi kerak.
 */
class EnsureAdminUser
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() instanceof AdminUser) {
            return response()->json([
                'message' => 'Faqat Central Admin uchun.',
                'code' => 'central_admin_required',
            ], 403);
        }

        return $next($request);
    }
}
