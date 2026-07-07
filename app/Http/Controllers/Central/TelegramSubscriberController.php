<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\TelegramConfig;
use App\Models\TgUser;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;

/**
 * Yagona bot obunachilarini boshqarish — markaziy admin (super-admin).
 * Reestr markaziy (tg_users, central DB). Bloklash / guruh-kanaldan chiqish.
 */
class TelegramSubscriberController extends Controller
{
    /** Barcha obunachilar. */
    public function index(): JsonResponse
    {
        $rows = TgUser::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TgUser $u) => [
                'id' => $u->id,
                'chat_id' => $u->chat_id,
                'name' => $u->name,
                'username' => $u->username,
                'type' => $u->type,
                'status' => $u->status,
                'created_at' => $u->created_at?->toIso8601String(),
            ]);

        return response()->json($rows);
    }

    /** Bloklash — bot bu chat xabarlariga javob bermaydi. */
    public function block(TgUser $tgUser): JsonResponse
    {
        $tgUser->update(['status' => TgUser::STATUS_BLOCKED]);

        return response()->json(['ok' => true]);
    }

    /** Blokdan chiqarish. */
    public function unblock(TgUser $tgUser): JsonResponse
    {
        $tgUser->update(['status' => TgUser::STATUS_ACTIVE]);

        return response()->json(['ok' => true]);
    }

    /** Bot guruh/kanaldan chiqadi (leaveChat) va reestrdan o'chiriladi. */
    public function leave(TgUser $tgUser, TelegramService $telegram): JsonResponse
    {
        $token = TelegramConfig::activeToken();
        if ($token === '') {
            return response()->json(['message' => 'Бот не настроен.'], 422);
        }

        $res = $telegram->leaveChat($token, $tgUser->chat_id);
        if ($res['ok'] ?? false) {
            $tgUser->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['message' => $res['error'] ?? 'Не удалось выйти из чата.'], 422);
    }

    /** Reestrdan o'chirish (chatga tegmasdan). */
    public function destroy(TgUser $tgUser): JsonResponse
    {
        $tgUser->delete();

        return response()->json(['ok' => true]);
    }
}
