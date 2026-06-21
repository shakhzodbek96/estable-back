<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend stack'ini (PHP/Laravel) oshkor qiluvchi header'larni yashiradi.
 * Asosiy maqsad: server texnologiyasini fingerprint qilishni qiyinlashtirish.
 */
class ObscureFingerprint
{
    public function handle(Request $request, Closure $next): Response
    {
        // PHP SAPI darajasida qo'shilgan X-Powered-By'ni o'chiramiz
        if (function_exists('header_remove')) {
            @header_remove('X-Powered-By');
        }

        $response = $next($request);

        // Stack'ni oshkor qiluvchi header'larni tozalash
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('x-powered-by');

        return $response;
    }
}
