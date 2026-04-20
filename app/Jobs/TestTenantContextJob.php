<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant context ichida queue worker to'g'ri ishlashini tekshirish uchun job.
 *
 * Test sxemasi:
 *   tenant A kontekstida dispatch() →
 *   stancl QueueTenancyBootstrapper job payload'iga tenant_id qo'yadi →
 *   queue:work tenant_id ni o'qib tenancy()->initialize() qiladi →
 *   handle() tenant A kontekstda ishlaydi → Users count tenant A dan.
 */
class TestTenantContextJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $label = 'test')
    {
    }

    public function handle(): void
    {
        $searchPath = DB::connection()->getConfig('search_path');
        $connectionName = DB::connection()->getName();
        $tenantId = tenant('id') ?? 'CENTRAL';

        // Tenant schema'dagi users jadvaliga murojat — tenancy ishlasa shop bor bo'ladi
        $usersCount = Schema::hasTable('users') ? DB::table('users')->count() : -1;

        $message = sprintf(
            '[TestTenantContextJob] label=%s tenant=%s connection=%s search_path=%s users=%d',
            $this->label,
            $tenantId,
            $connectionName,
            $searchPath ?: 'null',
            $usersCount
        );

        Log::info($message);
    }
}
