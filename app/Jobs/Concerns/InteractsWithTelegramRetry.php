<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Telegram sendMessage natijasini queue-retry mantig'iga o'giradi.
 *
 * Job'lar shu trait'ni ishlatib, natijaga qarab:
 *  - 429 (retry_after) → `release()` — o'sha soniyadan keyin qayta navbatga
 *  - 5xx / tarmoq (retryable) → exception → backoff bilan qayta urinish
 *  - doimiy 4xx (chat not found, unauthorized) → log, qayta urinilmaydi
 *
 * `release()` uchun job'da `Illuminate\Queue\InteractsWithQueue` bo'lishi kerak
 * (Foundation `Queueable` uni o'z ichiga oladi).
 */
trait InteractsWithTelegramRetry
{
    protected function handleTelegramResult(array $result, string $context = ''): void
    {
        if (($result['ok'] ?? false) || ($result['skipped'] ?? false)) {
            return;
        }

        // 429 — rate limit: Telegram aytган soniyadan keyin qayta (+1s zaxira)
        $retryAfter = (int) ($result['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            $this->release($retryAfter + 1);
            return;
        }

        // Vaqtinchalik xato (network / 5xx) — exception tashlaymiz → backoff retry
        if ($result['retryable'] ?? false) {
            throw new \RuntimeException('Telegram temporary error: ' . ($result['error'] ?? 'unknown'));
        }

        // Doimiy xato — qayta urinishning ma'nosi yo'q
        Log::warning('[Telegram] permanent send failure' . ($context ? " ({$context})" : '') . ': ' . ($result['error'] ?? 'unknown'));
    }
}
