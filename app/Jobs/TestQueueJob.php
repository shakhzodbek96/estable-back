<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Queue worker'ni sinash uchun health check job.
 *
 * Bajarilganda `health:queue:last_run` cache kalitiga timestamp yozadi.
 * Health endpoint buni o'qib, queue worker real ishlayotganini tasdiqlaydi.
 *
 * Real ilovalarda foydalanilmaydi — faqat monitoring uchun.
 */
class TestQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cache kaliti — queueStatus endpoint shu kalitni o'qiydi.
     */
    public const CACHE_KEY = 'health:queue:last_run';

    public function __construct(public ?string $dispatchedBy = null)
    {
    }

    public function handle(): void
    {
        $now = now();

        Cache::put(self::CACHE_KEY, [
            'timestamp' => $now->toIso8601String(),
            'unixtime' => $now->unix(),
            'dispatched_by' => $this->dispatchedBy,
        ], now()->addDay());

        Log::info('TestQueueJob bajarildi', [
            'at' => $now->toIso8601String(),
            'dispatched_by' => $this->dispatchedBy,
        ]);
    }
}
