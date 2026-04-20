<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Estable file-cache tenant bootstrapper.
 *
 * Stancl'ning standart `CacheTenancyBootstrapper` cache tagging ishlatadi,
 * lekin Laravel file driver tagging'ni qo'llab-quvvatlamaydi. Estable cache
 * file-based (foydalanuvchi talabi, oddiy konfiguratsiya) bo'lgani sababli,
 * men PATH-BASED izolyatsiya qilaman:
 *
 *   Central:  storage/framework/cache/data/...
 *   Tenant A: storage/tenant_<id-A>/framework/cache/data/...
 *   Tenant B: storage/tenant_<id-B>/framework/cache/data/...
 *
 * Bu FilesystemTenancyBootstrapper bilan juda mos tushadi —
 * `storage_path()` o'zi tenant uchun suffix qilinadi, cache fayllar avto
 * tenant directory'ga tushadi. Biz faqat `config('cache.stores.file.path')`
 * ni bootstrap paytida yangilab, cache driver instance'ni forget qilamiz.
 *
 * IMPORTANT: Stancl bootstrappers tartibi muhim — FilesystemTenancyBootstrapper
 * bu bootstrapper'dan OLDIN bajarilishi kerak (u `storage_path()` ni yangilaydi).
 */
class FileCacheTenancyBootstrapper implements TenancyBootstrapper
{
    protected ?string $originalPath = null;
    protected ?string $originalLockPath = null;

    public function __construct(protected Application $app)
    {
    }

    public function bootstrap(Tenant $tenant): void
    {
        // Asl path'larni saqlab qoldiramiz (revert paytida qaytarish uchun)
        $this->originalPath = $this->app['config']->get('cache.stores.file.path');
        $this->originalLockPath = $this->app['config']->get('cache.stores.file.lock_path');

        // storage_path() allaqachon FilesystemTenancyBootstrapper tomonidan
        // tenant-specific bo'lib yangilangan. Shu asosda cache path ni qayta hisoblaymiz.
        $tenantPath = storage_path('framework/cache/data');

        $this->app['config']->set('cache.stores.file.path', $tenantPath);
        $this->app['config']->set('cache.stores.file.lock_path', $tenantPath);

        // Cache manager'da file driver instance'ini forget qilamiz —
        // keyingi Cache::store('file') chaqiruvida yangi path bilan yaratiladi.
        Cache::forgetDriver('file');
        Cache::clearResolvedInstances();
    }

    public function revert(): void
    {
        if ($this->originalPath !== null) {
            $this->app['config']->set('cache.stores.file.path', $this->originalPath);
            $this->app['config']->set('cache.stores.file.lock_path', $this->originalLockPath);
        }

        Cache::forgetDriver('file');
        Cache::clearResolvedInstances();

        $this->originalPath = null;
        $this->originalLockPath = null;
    }
}
