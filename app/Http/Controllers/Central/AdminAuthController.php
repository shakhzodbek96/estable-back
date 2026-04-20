<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Central Admin Panel auth.
 *
 * Endpointlar faqat `admin.estable.uz` frontend tomonidan chaqiriladi.
 * Token central schema'dagi `personal_access_tokens` jadvaliga yoziladi —
 * tenant user tokenlar'dan butunlay ajratilgan.
 */
class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /** @var AdminUser|null $admin */
        $admin = AdminUser::where('username', $credentials['username'])->first();

        if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'username' => ['Неверный логин или пароль.'],
            ]);
        }

        if (!$admin->is_active) {
            throw ValidationException::withMessages([
                'username' => ['Akkaunt to\'xtatilgan.'],
            ]);
        }

        $admin->update(['last_login_at' => now()]);

        $token = $admin->createToken(
            'central-admin-token',
            ['*'],
            now()->addDays(30)
        )->plainTextToken;

        return response()->json([
            'admin' => $admin->fresh(),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $admin = $request->user();

        if (!Hash::check($request->input('current_password'), $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Joriy parol noto\'g\'ri.'],
            ]);
        }

        $admin->password = $request->input('password');
        $admin->save();

        return response()->json(['message' => 'Parol yangilandi.']);
    }
}
