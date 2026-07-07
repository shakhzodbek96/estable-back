<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API bilan past-darajali aloqa (transport qatlami).
 *
 * Faqat HTTP so'rov yuboradi — hisobot matnini shakllantirish
 * TelegramReportService zimmasida.
 */
class TelegramService
{
    private const API = 'https://api.telegram.org';

    /**
     * Kanal/guruhga HTML matnli xabar yuboradi.
     *
     * Natija (queue job retry logikasi uchun ham):
     *  - retry_after — 429 (rate limit) bo'lsa, shuncha soniyadan keyin qayta urinish kerak
     *  - retryable   — vaqtinchalik xato (network / 5xx) — qayta urinsa bo'ladi
     *  - status      — HTTP status kodi
     *
     * @return array{ok: bool, error: string|null, retry_after?: int, retryable?: bool, status?: int}
     */
    public function sendMessage(string $token, string $chatId, string $text): array
    {
        try {
            $res = Http::timeout(15)
                ->asForm()
                ->post(self::API . "/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            // Tarmoq uzilishi / timeout — vaqtinchalik, qayta urinsa bo'ladi
            Log::warning('[Telegram] sendMessage exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Не удалось связаться с Telegram.', 'retryable' => true];
        }

        if ($res->successful() && $res->json('ok') === true) {
            return ['ok' => true, 'error' => null];
        }

        $status = $res->status();
        $desc = (string) ($res->json('description') ?? ('HTTP ' . $status));
        $retryAfter = (int) ($res->json('parameters.retry_after') ?? 0);

        Log::warning("[Telegram] sendMessage failed (HTTP {$status}): {$desc}");

        return [
            'ok' => false,
            'error' => $this->humanError($desc),
            'retry_after' => $retryAfter,      // 429 → > 0
            'retryable' => $status >= 500,     // 5xx — vaqtinchalik server xatosi
            'status' => $status,
        ];
    }

    /**
     * Bot token'ning haqiqiyligini tekshiradi (getMe).
     */
    public function verifyToken(string $token): bool
    {
        try {
            return Http::timeout(10)->get(self::API . "/bot{$token}/getMe")->json('ok') === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Bot haqida ma'lumot (getMe) — id, username, first_name.
     *
     * @return array{id:int, username:?string, first_name:?string}|null
     */
    public function getBotInfo(string $token): ?array
    {
        try {
            $res = Http::timeout(10)->get(self::API . "/bot{$token}/getMe");
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful() || $res->json('ok') !== true) {
            return null;
        }

        return [
            'id' => (int) $res->json('result.id'),
            'username' => $res->json('result.username'),
            'first_name' => $res->json('result.first_name'),
        ];
    }

    /**
     * Webhook URL'ni o'rnatadi. secret — Telegram har so'rovda
     * `X-Telegram-Bot-Api-Secret-Token` header'ida qaytaradi (autentifikatsiya).
     *
     * @return array{ok: bool, error: string|null}
     */
    public function setWebhook(string $token, string $url, string $secret): array
    {
        try {
            $res = Http::timeout(15)->asForm()->post(self::API . "/bot{$token}/setWebhook", [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => json_encode(['message', 'my_chat_member', 'channel_post']),
                'drop_pending_updates' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Telegram] setWebhook exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Не удалось связаться с Telegram.'];
        }

        if ($res->successful() && $res->json('ok') === true) {
            return ['ok' => true, 'error' => null];
        }

        $desc = (string) ($res->json('description') ?? ('HTTP ' . $res->status()));
        Log::warning('[Telegram] setWebhook failed: ' . $desc);

        return ['ok' => false, 'error' => $this->humanError($desc)];
    }

    /**
     * Webhook'ni o'chiradi (bot o'chirilganda/token o'zgarganda).
     */
    public function deleteWebhook(string $token): void
    {
        try {
            Http::timeout(10)->asForm()->post(self::API . "/bot{$token}/deleteWebhook", [
                'drop_pending_updates' => false,
            ]);
        } catch (\Throwable) {
            // jim — bu tozalash amaliyoti, muhim emas
        }
    }

    /**
     * Bot berilgan guruh/kanaldan chiqadi (leaveChat).
     *
     * @return array{ok: bool, error: string|null}
     */
    public function leaveChat(string $token, string $chatId): array
    {
        try {
            $res = Http::timeout(10)->asForm()->post(self::API . "/bot{$token}/leaveChat", [
                'chat_id' => $chatId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Telegram] leaveChat exception: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Не удалось связаться с Telegram.'];
        }

        if ($res->successful() && $res->json('ok') === true) {
            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => (string) ($res->json('description') ?? ('HTTP ' . $res->status()))];
    }

    /** Telegram xato tavsifini foydalanuvchi tushunadigan ruscha matnga o'giradi. */
    private function humanError(string $desc): string
    {
        return match (true) {
            str_contains($desc, 'chat not found')
                => 'Чат не найден. Проверьте Chat ID и добавьте бота в канал/группу.',
            str_contains($desc, 'bot was kicked'), str_contains($desc, 'not enough rights'),
            str_contains($desc, 'CHAT_ADMIN_REQUIRED')
                => 'У бота нет прав в этом канале/группе. Добавьте его как администратора.',
            str_contains($desc, 'Unauthorized')
                => 'Неверный токен бота.',
            default
                => 'Ошибка Telegram: ' . $desc,
        };
    }
}
