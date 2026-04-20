<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Estable multi-tenant SaaS CORS sozlamalari.
    |
    | Backend:  api.estable.uz (bitta endpoint)
    | Tenant frontendlar: *.estable.uz (har biznes egasi uchun subdomain)
    | Central admin:      admin.estable.uz
    |
    | Har tenant o'z subdomain'idan backend'ga so'rov yuboradi. CORS
    | yordamida brauzer bu so'rovlarni ruxsat beriladi, chunki:
    |  - allowed_origins_patterns estable.uz subdomainlarini wildcard qiladi
    |  - allowed_origins local dev uchun aniq xostlar
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /**
     * Aniq xostlar — local development uchun.
     */
    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
    ],

    /**
     * Wildcard patterns — production tenant subdomainlari.
     * Regex format: slashlar bilan yoki bo'ronlar bilan.
     * Bu pattern '*.estable.uz' ga mos keladi (http yoki https).
     * (:\d+)? — ixtiyoriy port, local dev uchun: shop1.estable.uz:5173
     */
    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)*estable\.uz(:\d+)?$#i',
    ],

    'allowed_headers' => ['*'],

    /**
     * Frontend'ga ko'rinadigan headerlar. X-Tenant-Id qaytarish mumkin,
     * shunda frontend qaysi tenant kontekstda javob kelganini tasdiqlashi mumkin.
     */
    'exposed_headers' => [
        'X-Tenant-Id',
    ],

    /**
     * Preflight cache — 1 soat. Browser har request oldidan OPTIONS yubormasligi uchun.
     */
    'max_age' => 3600,

    /**
     * Credentials (cookie, Authorization header) — false, chunki Sanctum API
     * token-based (Bearer), cookie ishlatilmaydi. Agar kelajakda session-based
     * auth qo'shilsa, `true` ga o'zgartirish kerak.
     */
    'supports_credentials' => false,

];
