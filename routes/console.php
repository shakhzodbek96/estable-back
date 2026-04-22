<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks — Estable multi-tenant
|--------------------------------------------------------------------------
|
| Ikki turdagi task'lar mavjud:
|
|  1. CENTRAL tasks  — central DB kontekstida ishlaydi, `tenants` jadvaliga
|                      ta'sir qiladi (trial expiry check, billing, va h.k.)
|
|  2. TENANT tasks   — har tenant uchun alohida bajariladi. Stancl'ning
|                      `tenants:run` komandasidan foydalaniladi, u har
|                      tenant'ni aylanma ravishda ishga tushiradi va
|                      tenant kontekstni o'rnatadi.
|
| Production'da `php artisan schedule:work` yoki cron'da
| `* * * * * php artisan schedule:run` ishga tushirilishi kerak.
|
*/

// ======================================================================
// MISOL 1: CENTRAL task (barcha tenantlar'ni tekshirish)
// ----------------------------------------------------------------------
// Har kun ertalab 03:00'da trial muddati tugagan tenantlar'ni suspend qilish.
// Bu task central DB da `tenants` jadvaliga to'g'ridan-to'g'ri SQL query yuboradi.
// ======================================================================

Schedule::call(function () {
    \App\Models\Tenant::query()
        ->where('status', \App\Models\Tenant::STATUS_ACTIVE)
        ->whereNotNull('trial_ends_at')
        ->where('trial_ends_at', '<', now())
        ->update(['status' => \App\Models\Tenant::STATUS_TRIAL_EXPIRED]);
})->name('tenants:expire-trials')->dailyAt('03:00');


// ======================================================================
// Scheduler heartbeat — har daqiqada timestamp yozib turadi.
// GET /api/central/health/scheduler-status endpoint shuni o'qib,
// scheduler servisi ishlayotganligini tasdiqlaydi.
// ======================================================================

Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put(
        \App\Http\Controllers\Central\HealthController::SCHEDULER_CACHE_KEY,
        [
            'timestamp' => now()->toIso8601String(),
            'unixtime' => now()->unix(),
        ],
        now()->addDay()
    );
})->name('health:scheduler-heartbeat')->everyMinute()->withoutOverlapping();


// ======================================================================
// MISOL 2: TENANT task (har tenant uchun alohida)
// ----------------------------------------------------------------------
// Har tenant DB'si ichida kunlik ma'lumot (example: stale cart cleanup)
// Sintaksis: `artisan tenants:run <command>` — har tenant uchun ishlaydi.
//
// Bu yerda `inventory:cleanup-stale` degan hypothetical komandani chaqiramiz,
// u har tenant DB'sida 30 kundan ko'p bo'sh turgan `in_stock` tovarlar'ni
// `archived` status'ga o'tkazishi mumkin. Komanda hali yaratilmagan —
// kelajakda kerak bo'lsa `php artisan make:command InventoryCleanup` bilan.
//
// Hozir misol sifatida yozilgan, kod izohli.
// ======================================================================

// Schedule::command('tenants:run inventory:cleanup-stale')
//     ->name('inventory:cleanup-stale')
//     ->dailyAt('02:30');


// ======================================================================
// MISOL 3: Tenantga xos — Telegram kunlik hisobot (kelajak uchun)
// ----------------------------------------------------------------------
// Agar keyinchalik Telegram bot qo'shilsa, har tenant admin'iga kunlik
// savdo hisobotini yuboradigan task shunday yoziladi.
//
// Schedule::command('tenants:run telegram:daily-report')
//     ->dailyAt('21:00');
