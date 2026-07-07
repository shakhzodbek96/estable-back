<?php

namespace App\Jobs;

use App\Services\TelegramReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Kunlik Telegram hisobotini yuboradigan queue job.
 *
 * MUHIM: tenant kontekstida dispatch qilinadi (scheduler `tenants:run` orqali
 * yoki tenant HTTP so'rovidan). Stancl QueueTenancyBootstrapper payload'ga
 * tenant_id qo'yadi va worker handle()'ni o'sha tenant kontekstida ishga tushiradi.
 * Shu sabab bu yerda tenant instance/ID uzatilmaydi — Setting va ReportService
 * avtomatik tenant schema'sida ishlaydi.
 */
class SendTelegramDailyReport implements ShouldQueue
{
    use Queueable;

    /** Xatolikda 3 martagacha urinish (Telegram 429 / vaqtincha uzilish uchun). */
    public int $tries = 3;

    /** Urinishlar orasida 60 soniya kutish. */
    public int $backoff = 60;

    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {
    }

    public function handle(TelegramReportService $service): void
    {
        $result = $service->sendDailyReport($this->dateFrom, $this->dateTo);

        if (! ($result['ok'] ?? false) && ! ($result['skipped'] ?? false)) {
            Log::warning('[Telegram] daily report failed: ' . ($result['error'] ?? 'unknown'));
        }
    }
}
