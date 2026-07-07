<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\TgOtp;
use App\Models\TgUser;

/**
 * Telegram obunachilarni boshqarish (tenant kontekstida):
 *  - OTP generatsiya (entity'ni chat'ga bog'lash uchun)
 *  - kirish (webhook) update'larini qayta ishlash → tg_users yozish
 *  - obunachilar ro'yxati
 *  - entity'ga (user/customer/investor) shaxsan xabar yuborish
 *
 * Transport — TelegramService; bot config — TelegramReportService::config().
 */
class TgSubscriberService
{
    public function __construct(
        private TelegramService $telegram,
        private TelegramReportService $reports,
    ) {
    }

    /**
     * Entity uchun aktivatsiya kodi + deep-link yaratadi.
     *
     * @return array{otp: string, link: string|null, bot_username: string|null, expires_at: string|null}
     */
    public function generateOtp(string $model, int $modelId): array
    {
        $otp = TgOtp::generateFor($model, $modelId);
        $cfg = $this->reports->config();

        $link = $cfg['bot_username'] !== ''
            ? "https://t.me/{$cfg['bot_username']}?start={$otp->otp}"
            : null;

        return [
            'otp' => $otp->otp,
            'link' => $link,
            'bot_username' => $cfg['bot_username'] ?: null,
            'expires_at' => $otp->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Telegram'dan kelgan bitta update'ni qayta ishlaydi (webhook chaqiradi).
     */
    public function handleUpdate(array $update): void
    {
        $token = $this->reports->config()['bot_token'];

        // Bot guruh/kanaldan chiqarilsa — obunachini o'chiramiz
        if (isset($update['my_chat_member'])) {
            $status = $update['my_chat_member']['new_chat_member']['status'] ?? '';
            $chatId = (string) ($update['my_chat_member']['chat']['id'] ?? '');
            if ($chatId !== '' && in_array($status, ['left', 'kicked'], true)) {
                TgUser::query()->where('chat_id', $chatId)->delete();
            }
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

        $text = trim((string) ($message['text'] ?? ''));
        $from = $message['from'] ?? [];
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $name = $name !== '' ? $name : ($chat['title'] ?? null);
        $username = $from['username'] ?? ($chat['username'] ?? null);
        $type = $chat['type'] ?? null;

        // Kodni ajratamiz: "/start 123456" yoki faqat "123456"
        $code = null;
        if (preg_match('/^\/start\s+(\S+)/', $text, $m)) {
            $code = $m[1];
        } elseif (preg_match('/^\d{6}$/', $text)) {
            $code = $text;
        }

        if ($code !== null) {
            $this->activate($code, $chatId, $name, $username, $type, $token);
            return;
        }

        // Kodsiz /start yoki guruh/kanal — bog'lanmagan holda ro'yxatga tushiramiz
        if (str_starts_with($text, '/start') || ($type && $type !== 'private')) {
            TgUser::query()->firstOrCreate(
                ['chat_id' => $chatId],
                ['name' => $name, 'username' => $username, 'type' => $type],
            );
        }

        if (str_starts_with($text, '/start') && $token !== '') {
            $this->telegram->sendMessage(
                $token,
                $chatId,
                '👋 Отправьте код активации, полученный в системе, чтобы подключить уведомления.',
            );
        }
    }

    /**
     * Kod bo'yicha chat'ni entity'ga bog'laydi.
     */
    private function activate(string $code, string $chatId, ?string $name, ?string $username, ?string $type, string $token): void
    {
        $otp = TgOtp::query()->where('otp', $code)->where('status', TgOtp::STATUS_NEW)->first();

        if (! $otp || ! $otp->isValid()) {
            if ($token !== '') {
                $this->telegram->sendMessage($token, $chatId, '❌ Код недействителен или истёк. Запросите новый код.');
            }
            return;
        }

        TgUser::query()->updateOrCreate(
            ['chat_id' => $chatId],
            [
                'model' => $otp->model,
                'model_id' => $otp->model_id,
                'name' => $name,
                'username' => $username,
                'type' => $type,
            ],
        );

        $otp->update(['status' => TgOtp::STATUS_USED]);

        // Customer entity'sida chat_id ustuni bor — sinxronlaymiz (tez tekshiruv uchun)
        if ($otp->model === 'customer') {
            Customer::query()->whereKey($otp->model_id)->update(['chat_id' => $chatId]);
        }

        if ($token !== '') {
            $this->telegram->sendMessage($token, $chatId, $this->confirmText($otp->model));
        }
    }

    private function confirmText(string $model): string
    {
        $label = match ($model) {
            'user' => 'сотрудника',
            'customer' => 'клиента',
            'investor' => 'инвестора',
            default => '',
        };

        return trim("✅ Telegram успешно подключён для {$label}.")
            . "\nТеперь уведомления будут приходить сюда.";
    }

    /**
     * Barcha obunachilar ro'yxati (bog'langan entity nomi bilan).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSubscribers(): array
    {
        return TgUser::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TgUser $u) {
                return [
                    'id' => $u->id,
                    'chat_id' => $u->chat_id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'type' => $u->type,
                    'model' => $u->model,
                    'model_id' => $u->model_id,
                    'entity_name' => $u->entity()?->name,
                    'created_at' => $u->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Entity'ga bog'langan barcha chat'larga xabar yuboradi.
     *
     * @return array{ok: bool, count: int, error?: string}
     */
    public function sendToEntity(string $model, int $modelId, string $text): array
    {
        $cfg = $this->reports->config();
        if ($cfg['bot_token'] === '') {
            return ['ok' => false, 'count' => 0, 'error' => 'Бот не настроен.'];
        }

        $chatIds = TgUser::query()->where('model', $model)->where('model_id', $modelId)->pluck('chat_id');
        $ok = true;

        foreach ($chatIds as $chatId) {
            $r = $this->telegram->sendMessage($cfg['bot_token'], (string) $chatId, $text);
            $ok = $ok && ($r['ok'] ?? false);
        }

        return ['ok' => $ok, 'count' => $chatIds->count()];
    }

    /**
     * Joriy tenant uchun webhook URL'ini o'rnatadi (bot config saqlanganda chaqiriladi).
     *
     * @return array{ok: bool, error: string|null}
     */
    public function ensureWebhook(): array
    {
        $cfg = $this->reports->config();
        if ($cfg['bot_token'] === '') {
            return ['ok' => false, 'error' => 'Нет токена бота.'];
        }
        if ($cfg['webhook_secret'] === '') {
            return ['ok' => false, 'error' => 'Нет секрета webhook.'];
        }

        return $this->telegram->setWebhook($cfg['bot_token'], $this->webhookUrl(), $cfg['webhook_secret']);
    }

    /** Joriy tenant uchun webhook URL. */
    public function webhookUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base . '/api/central/telegram/webhook/' . tenant('id');
    }
}
