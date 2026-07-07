<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TgUser;
use App\Services\TgSubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Telegram obunachilar moduli (tenant, faqat admin).
 */
class TgSubscriberController extends Controller
{
    public function __construct(private TgSubscriberService $service)
    {
    }

    /** Barcha obunachilar ro'yxati. */
    public function index(): JsonResponse
    {
        return response()->json($this->service->listSubscribers());
    }

    /** Entity (user/customer/investor) uchun aktivatsiya kodi + deep-link. */
    public function generateOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', Rule::in(TgUser::allowedModels())],
            'model_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->generateOtp($data['model'], (int) $data['model_id']));
    }

    /** Obunachini o'chirish (ro'yxatdan). */
    public function destroy(TgUser $tgUser): JsonResponse
    {
        $tgUser->delete();

        return response()->json(['ok' => true]);
    }
}
