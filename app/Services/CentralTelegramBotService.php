<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage;
use App\Models\TelegramConfig;
use App\Models\TgUser;

/**
 * Yagona markaziy info-bot mantig'i (webhook + webhook o'rnatish).
 *
 * Bitta bot butun SaaS uchun; obunachilar reestri MARKAZIY (tg_users, central DB).
 * Foydalanuvchi `/chat_id` yozsa yoki bot guruh/kanalga qo'shilsa — chat_id qaytaradi.
 */
class CentralTelegramBotService
{
    public function __construct(private TelegramService $telegram)
    {
    }

    /**
     * Telegram'dan kelgan bitta update'ni qayta ishlaydi (webhook chaqiradi).
     */
    public function handleUpdate(array $update): void
    {
        $token = TelegramConfig::activeToken();

        if (isset($update['my_chat_member'])) {
            $this->handleChatMember($update['my_chat_member'], $token);
            return;
        }

        $message = $update['message'] ?? $update['channel_post'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        if ($chatId === '') {
            return;
        }

        // Bloklangan chat — butunlay e'tiborsiz qoldiramiz
        $existing = TgUser::query()->where('chat_id', $chatId)->first();
        if ($existing && $existing->isBlocked()) {
            return;
        }

        [$name, $username, $type] = $this->identity($message, $chat);

        // Reestrga yozamiz/yangilaymiz (status'ga tegmaymiz)
        TgUser::query()->updateOrCreate(
            ['chat_id' => $chatId],
            ['name' => $name, 'username' => $username, 'type' => $type],
        );

        // /chat_id (yoki /start) — chat_id qaytaramiz
        $text = trim((string) ($message['text'] ?? ''));
        if ($token !== '' && $this->isChatIdCommand($text)) {
            $this->sendChatId($token, $chatId, $type);
        }
    }

    /** Bot guruh/kanalga qo'shildi/chiqarildi. */
    private function handleChatMember(array $mcm, string $token): void
    {
        $newStatus = $mcm['new_chat_member']['status'] ?? '';
        $oldStatus = $mcm['old_chat_member']['status'] ?? '';
        $chat = $mcm['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        if ($chatId === '') {
            return;
        }

        // Chiqarildi/bloklandi — reestrdan o'chiramiz
        if (in_array($newStatus, ['left', 'kicked'], true)) {
            TgUser::query()->where('chat_id', $chatId)->delete();
            return;
        }

        // Yangi qo'shildi — reestrga yozib, chat_id yuboramiz
        $wasAbsent = in_array($oldStatus, ['left', 'kicked', ''], true);
        $isPresent = in_array($newStatus, ['member', 'administrator', 'creator'], true);
        if ($wasAbsent && $isPresent) {
            $type = $chat['type'] ?? null;
            TgUser::query()->updateOrCreate(
                ['chat_id' => $chatId],
                ['name' => $chat['title'] ?? null, 'username' => $chat['username'] ?? null, 'type' => $type],
            );
            if ($token !== '') {
                $this->sendChatId($token, $chatId, $type);
            }
        }
    }

    /** "/chat_id", "/chatid", "/chat_id@bot", "/start" ni aniqlaydi. */
    private function isChatIdCommand(string $text): bool
    {
        return (bool) preg_match('/^\/(chat_?id|start)(@\w+)?$/i', $text);
    }

    private function sendChatId(string $token, string $chatId, ?string $type): void
    {
        $isPrivate = ! $type || $type === 'private';

        $text = "🆔 Chat ID: <code>{$chatId}</code>";
        $text .= $isPrivate
            ? "\n\nСкопируйте этот ID и вставьте его в настройках вашего магазина."
            : "\n\nСкопируйте этот ID чата и вставьте его в настройках магазина, чтобы получать сюда отчёты.";

        // Webhook tez javob berishi uchun yuborishni navbatga qo'yamiz (429 retry job'da)
        SendTelegramMessage::dispatch($chatId, $text, $token);
    }

    /** Update'dan ism/username/type ni ajratib oladi. */
    private function identity(array $message, array $chat): array
    {
        $from = $message['from'] ?? [];
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $name = $name !== '' ? $name : ($chat['title'] ?? null);
        $username = $from['username'] ?? ($chat['username'] ?? null);
        $type = $chat['type'] ?? null;

        return [$name, $username, $type];
    }

    // ---- Webhook (markaziy, bitta URL) ----

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/central/telegram/webhook';
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function setWebhook(string $token): array
    {
        $secret = (string) config('telegram.webhook_secret', '');
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Webhook secret (.env) не задан.'];
        }

        return $this->telegram->setWebhook($token, $this->webhookUrl(), $secret);
    }
}
