<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithTelegramRetry;
use App\Services\TelegramReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * POS sotuvi amalga oshgach, tenant Telegram kanaliga bitta sotuv haqida
 * bildirishnoma yuboradi.
 *
 * SaleService::create() ichida `->afterCommit()` bilan dispatch qilinadi —
 * ya'ni faqat sotuv tranzaksiyasi commit bo'lgach navbatga tushadi (worker
 * sotuvni o'qiy olishi kafolatlanadi). Sotuv ID uzatiladi, instance EMAS
 * (tenant-aware queue + serializatsiya qoidasi).
 *
 * 429 (rate limit) va 5xx uchun retry — InteractsWithTelegramRetry.
 */
class SendSaleTelegramNotification implements ShouldQueue
{
    use InteractsWithTelegramRetry;
    use Queueable;

    public int $tries = 6;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $saleId)
    {
    }

    public function handle(TelegramReportService $service): void
    {
        $result = $service->sendSaleNotification($this->saleId);
        $this->handleTelegramResult($result, 'sale ' . $this->saleId);
    }
}
