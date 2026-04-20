<?php

use App\Http\Controllers\Central\AdminAuthController;
use App\Http\Controllers\Central\AdminUserController;
use App\Http\Controllers\Central\DashboardController;
use App\Http\Controllers\Central\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central API Routes — Estable Admin Panel
|--------------------------------------------------------------------------
|
| Bu fayldagi route'lar CENTRAL DB (public schema) kontekstida ishlaydi.
| Tenant middleware YO'Q — `auth:sanctum` central schema'dagi
| `personal_access_tokens` jadvalidan token'ni o'qiydi va `AdminUser`
| modeliga bog'laydi.
|
| Base URL: /api/central/*
| Frontend:  admin.estable.uz (React + Vite + TypeScript + shadcn/ui)
|
*/

Route::get('ping', function () {
    return response()->json([
        'pong' => true,
        'scope' => 'central',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Auth (public)
Route::prefix('auth')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('change-password', [AdminAuthController::class, 'changePassword']);
    });
});

// Protected: central admin only
Route::middleware(['auth:sanctum', 'admin'])->group(function () {

    // Dashboard
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);

    // Tenants CRUD
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store']);
    Route::get('tenants/{id}', [TenantController::class, 'show']);
    Route::put('tenants/{id}', [TenantController::class, 'update']);
    Route::delete('tenants/{id}', [TenantController::class, 'destroy']);

    // Tenant lifecycle
    Route::post('tenants/{id}/suspend', [TenantController::class, 'suspend']);
    Route::post('tenants/{id}/activate', [TenantController::class, 'activate']);

    // Tenant admin password reset (yangi parol qaytaradi, must_change=true)
    Route::post('tenants/{id}/reset-admin-password', [TenantController::class, 'resetAdminPassword']);

    // Tenant schema ichidagi foydalanuvchilar ro'yxati
    Route::get('tenants/{id}/users', [TenantController::class, 'users']);

    // Super admin akkauntlarni boshqarish
    Route::get('admin-users', [AdminUserController::class, 'index']);
    Route::post('admin-users', [AdminUserController::class, 'store']);
    Route::delete('admin-users/{adminUser}', [AdminUserController::class, 'destroy']);
});
