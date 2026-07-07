<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\CentralTelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Yagona markaziy info-bot webhook.
 *
 * URL: POST /api/central/telegram/webhook
 * Autentifikatsiya: `X-Telegram-Bot-Api-Secret-Token` header == config('telegram.webhook_secret').
 * Har doim 200 qaytaradi (Telegram qayta-qayta yubormasligi uchun).
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, CentralTelegramBotService $bot): JsonResponse
    {
        $secret = (string) config('telegram.webhook_secret', '');
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($secret === '' || ! hash_equals($secret, $header)) {
            return response()->json(['ok' => true]); // noto'g'ri secret — jim rad
        }

        try {
            $bot->handleUpdate($request->all());
        } catch (\Throwable $e) {
            Log::warning('[Telegram] webhook error: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
