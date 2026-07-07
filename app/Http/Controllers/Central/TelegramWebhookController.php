<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TelegramReportService;
use App\Services\TgSubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Telegram webhook — barcha tenant botlaridan kelgan update'larni qabul qiladi.
 *
 * Telegram `Origin` header yubormaydi, shuning uchun tenant URL yo'lidan
 * ({tenant}) aniqlanadi va qo'lda `$tenant->run()` bilan kontekst ochiladi.
 * Autentifikatsiya — `X-Telegram-Bot-Api-Secret-Token` header (setWebhook'da
 * o'rnatilgan per-tenant secret bilan solishtiriladi).
 *
 * URL: POST /api/central/telegram/webhook/{tenant}
 *
 * Har doim 200 qaytaradi — aks holda Telegram update'ni qayta-qayta yuboradi.
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, string $tenant): JsonResponse
    {
        $tenantModel = Tenant::find($tenant);
        if (! $tenantModel) {
            return response()->json(['ok' => true]); // noma'lum tenant — jim
        }

        $update = $request->all();
        $secretHeader = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        try {
            $tenantModel->run(function () use ($update, $secretHeader) {
                $cfg = app(TelegramReportService::class)->config();

                // Secret tekshiruvi — noto'g'ri bo'lsa jim rad etamiz
                if ($cfg['webhook_secret'] === '' || ! hash_equals($cfg['webhook_secret'], $secretHeader)) {
                    return;
                }

                app(TgSubscriberService::class)->handleUpdate($update);
            });
        } catch (\Throwable $e) {
            Log::warning('[Telegram] webhook error (' . $tenant . '): ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
