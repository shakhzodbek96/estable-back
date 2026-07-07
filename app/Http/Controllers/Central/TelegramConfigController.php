<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\TelegramConfig;
use App\Services\CentralTelegramBotService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Markaziy info-bot konfiguratsiyasi (faqat super-admin).
 * Token shu yerdan yagona marta set qilinadi; secret .env'da.
 */
class TelegramConfigController extends Controller
{
    public function show(CentralTelegramBotService $bot): JsonResponse
    {
        $c = TelegramConfig::current();

        return response()->json([
            'has_token' => (bool) ($c && $c->bot_token),
            'bot_username' => $c?->bot_username,
            'is_active' => (bool) ($c?->is_active ?? true),
            'secret_configured' => config('telegram.webhook_secret', '') !== '',
            'webhook_url' => $bot->webhookUrl(),
        ]);
    }

    public function update(Request $request, TelegramService $telegram, CentralTelegramBotService $bot): JsonResponse
    {
        // ★ Shart: .env'da secret bo'lmasa — token set qilishga ruxsat yo'q
        if (config('telegram.webhook_secret', '') === '') {
            return response()->json([
                'message' => 'Сначала задайте TELEGRAM_WEBHOOK_SECRET в .env сервера.',
            ], 422);
        }

        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $c = TelegramConfig::current() ?? new TelegramConfig();

        $newToken = trim((string) ($data['bot_token'] ?? ''));
        if ($newToken !== '') {
            $info = $telegram->getBotInfo($newToken);
            if (! $info) {
                return response()->json(['message' => 'Неверный токен бота.'], 422);
            }
            $c->bot_token = $newToken;
            $c->bot_username = $info['username'] ?? null;
        }

        if (array_key_exists('is_active', $data)) {
            $c->is_active = (bool) $data['is_active'];
        }

        $c->save();
        TelegramConfig::forgetCache();

        // Webhook o'rnatamiz (token bor va aktiv bo'lsa)
        $webhookError = null;
        if ($c->bot_token && $c->is_active) {
            $res = $bot->setWebhook($c->bot_token);
            if (! ($res['ok'] ?? false)) {
                $webhookError = $res['error'] ?? null;
            }
        }

        return response()->json([
            'has_token' => (bool) $c->bot_token,
            'bot_username' => $c->bot_username,
            'is_active' => $c->is_active,
            'secret_configured' => true,
            'webhook_url' => $bot->webhookUrl(),
            'webhook_error' => $webhookError,
        ]);
    }
}
