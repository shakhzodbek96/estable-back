<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedById;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Central admin endpointlari — tenant middleware'siz, central DB'da ishlaydi.
            // URL prefix: /api/central/*
            Route::middleware('api')
                ->prefix('api/central')
                ->group(base_path('routes/central.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'tenant' => \App\Http\Middleware\InitializeTenancyByOriginHeader::class,
            'admin' => \App\Http\Middleware\EnsureAdminUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /**
         * Tenant topilmasa: frontend subdomain'i domains jadvalida yo'q
         * yoki tenant arxivlangan. 404 JSON javob qaytaramiz — brauzerga
         * xato HTML ko'rinmaydi, frontend uni tutib o'ziga xos message
         * ko'rsatadi ("Bu akkaunt topilmadi" kabi).
         */
        $exceptions->render(function (TenantCouldNotBeIdentifiedOnDomainException $e, Request $request) {
            return response()->json([
                'message' => 'Akkaunt topilmadi.',
                'code' => 'tenant_not_found',
                'hint' => 'Ushbu manzilga biriktirilgan biznes topilmadi. URL to\'g\'riligini tekshiring yoki administrator bilan bog\'laning.',
            ], 404);
        });

        $exceptions->render(function (TenantCouldNotBeIdentifiedById $e, Request $request) {
            return response()->json([
                'message' => 'Tenant aniqlanmadi.',
                'code' => 'tenant_not_found',
            ], 404);
        });
    })->create();
