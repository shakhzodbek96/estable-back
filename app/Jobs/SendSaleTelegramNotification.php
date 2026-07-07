<?php

namespace App\Jobs;

use App\Services\TelegramReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * POS sotuvi amalga oshgach, tenant Telegram kanaliga bitta sotuv haqida
 * bildirishnoma yuboradi.
 *
 * SaleService::create() ichida `->afterCommit()` bilan dispatch qilinadi —
 * ya'ni faqat sotuv tranzaksiyasi commit bo'lgach navbatga tushadi (worker
 * sotuvni o'qiy olishi kafolatlanadi). Sotuv ID uzatiladi, instance EMAS
 * (tenant-aware queue + serializatsiya qoidasi).
 */
class SendSaleTelegramNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $saleId)
    {
    }

    public function handle(TelegramReportService $service): void
    {
        $result = $service->sendSaleNotification($this->saleId);

        if (! ($result['ok'] ?? false) && ! ($result['skipped'] ?? false)) {
            Log::warning(
                '[Telegram] sale notification failed (sale ' . $this->saleId . '): '
                . ($result['error'] ?? 'unknown')
            );
        }
    }
}
