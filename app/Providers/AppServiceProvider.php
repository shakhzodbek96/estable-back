<?php

namespace App\Providers;

use App\Http\Controllers\Public\CatalogController;
use App\Models\Accessory;
use App\Models\Inventory;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Zaxira (Inventory/Accessory) o'zgarsa — "sotuvda bor" tovarlar statistikasi
        // keshini tozalaymiz (catalog/stats). Umumiy sonlar keshi vaqt bo'yicha qoladi.
        // Tenant kontekstida ishlaydi → Cache avto tenant-aware (faqat shu tenant kalitini o'chiradi).
        // saved (create/update), updated (increment/decrement), deleted — barchasi qamrab olinadi.
        $forgetInStock = static fn () => Cache::forget(CatalogController::CACHE_IN_STOCK);
        foreach ([Inventory::class, Accessory::class] as $model) {
            $model::saved($forgetInStock);
            $model::updated($forgetInStock);
            $model::deleted($forgetInStock);
        }
    }
}
