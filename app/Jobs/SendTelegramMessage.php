<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithTelegramRetry;
use App\Models\TelegramConfig;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bitta Telegram xabarni ASINXRON yuboradi (kanonik "message sender").
 *
 * 429 (rate limit) → `retry_after` soniyadan keyin qayta navbatga tushadi;
 * 5xx/tarmoq → backoff bilan qayta urinadi; doimiy xato → log.
 *
 * Token uzatilmasa markaziy bot tokeni (`TelegramConfig::activeToken()`) olinadi.
 */
class SendTelegramMessage implements ShouldQueue
{
    use InteractsWithTelegramRetry;
    use Queueable;

    /** Ko'proq urinish — 429 release'lari ham urinish hisoblanadi. */
    public int $tries = 8;

    /** 5xx/tarmoq exception'lari uchun oshib boruvchi backoff (soniya). */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        public string $chatId,
        public string $text,
        public ?string $token = null,
    ) {
    }

    public function handle(TelegramService $telegram): void
    {
        $token = $this->token ?: TelegramConfig::activeToken();

        if ($token === '' || $this->chatId === '' || $this->text === '') {
            return; // yuborishga narsa yo'q
        }

        $result = $telegram->sendMessage($token, $this->chatId, $this->text);
        $this->handleTelegramResult($result, 'chat=' . $this->chatId);
    }
}
