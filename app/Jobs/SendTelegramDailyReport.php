<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithTelegramRetry;
use App\Services\TelegramReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kunlik Telegram hisobotini yuboradigan queue job.
 *
 * MUHIM: tenant kontekstida dispatch qilinadi (scheduler `tenants:run` orqali
 * yoki tenant HTTP so'rovidan). Stancl QueueTenancyBootstrapper payload'ga
 * tenant_id qo'yadi va worker handle()'ni o'sha tenant kontekstida ishga tushiradi.
 * Shu sabab bu yerda tenant instance/ID uzatilmaydi — Setting va ReportService
 * avtomatik tenant schema'sida ishlaydi.
 *
 * 429 (rate limit) va 5xx uchun retry — InteractsWithTelegramRetry.
 */
class SendTelegramDailyReport implements ShouldQueue
{
    use InteractsWithTelegramRetry;
    use Queueable;

    public int $tries = 6;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {
    }

    public function handle(TelegramReportService $service): void
    {
        $result = $service->sendDailyReport($this->dateFrom, $this->dateTo);
        $this->handleTelegramResult($result, 'daily report');
    }
}
