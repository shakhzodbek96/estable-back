<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook secret
    |--------------------------------------------------------------------------
    | setWebhook'da Telegram'ga uzatiladi; Telegram har webhook so'rovida
    | `X-Telegram-Bot-Api-Secret-Token` header'ida qaytaradi. Bo'sh bo'lsa —
    | markaziy admin bot token'ni set qila olmaydi (xavfsizlik sharti).
    */
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
];
