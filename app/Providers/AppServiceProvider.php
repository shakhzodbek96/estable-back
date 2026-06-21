<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sanctum'ning '/sanctum/csrf-cookie' route'ini o'chiramiz — u Laravel/Sanctum
        // ishlatilishini oshkor qiladi. Biz token-auth (Bearer) ishlatamiz, SPA cookie
        // oqimi kerak emas. (SanctumServiceProvider::boot() shu config'ni o'qiydi.)
        config(['sanctum.routes' => false]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
