<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Central Admin Panel — super admin akkauntlarni boshqarish.
 *
 * Qoidalar:
 *  - Istalgan mavjud super admin yangi super admin qo'shishi mumkin
 *  - Super admin o'zini o'chira olmaydi
 *  - Super admin o'zidan OLDIN yaratilgan (kattaroq) adminni o'chira olmaydi
 *    (ya'ni keyinroq kelganlar "kamroq vakolatli")
 */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $admins = AdminUser::query()
            ->orderBy('id')
            ->get(['id', 'name', 'username', 'email', 'is_active', 'last_login_at', 'created_at']);

        return response()->json([
            'data' => $admins,
            'total' => $admins->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'regex:/^[a-z0-9_.-]+$/',
                Rule::unique('admin_users', 'username'),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('admin_users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'is_active' => ['boolean'],
        ], [
            'username.regex' => 'Username faqat kichik harflar, raqam, nuqta, defis yoki pastki chiziq bo\'lishi mumkin.',
            'username.unique' => 'Bu username allaqachon band.',
            'email.unique' => 'Bu email allaqachon band.',
        ]);

        $admin = AdminUser::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'], // hashed cast orqali avto
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Super admin muvaffaqiyatli qo\'shildi.',
            'admin' => $admin->fresh(),
        ], 201);
    }

    public function destroy(Request $request, AdminUser $adminUser): JsonResponse
    {
        $currentId = $request->user()->id;

        // O'zini o'chira olmaydi
        if ($adminUser->id === $currentId) {
            return response()->json([
                'message' => 'Siz o\'z akkauntingizni o\'chira olmaysiz.',
                'code' => 'cannot_delete_self',
            ], 403);
        }

        // O'zidan oldin yaratilgan (kattaroq vakolatli) adminni o'chira olmaydi
        if ($adminUser->id < $currentId) {
            return response()->json([
                'message' => 'Siz o\'zingizdan oldin yaratilgan adminni o\'chira olmaysiz.',
                'code' => 'cannot_delete_older_admin',
            ], 403);
        }

        // Kamida 1 ta super admin qolishi kerak
        if (AdminUser::count() <= 1) {
            return response()->json([
                'message' => 'Kamida bitta super admin qolishi kerak.',
                'code' => 'last_admin_cannot_be_deleted',
            ], 403);
        }

        $adminUser->delete();

        return response()->json(['message' => 'Super admin o\'chirildi.']);
    }
}
