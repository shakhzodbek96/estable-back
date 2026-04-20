<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Estable Central Admin Panel super admin modeli.
 *
 * Tenant `App\Models\User` bilan butunlay alohida — bu model faqat central
 * DB kontekstida ishlaydi. Sanctum token central schema'dagi
 * `personal_access_tokens` jadvaliga polymorphic bog'lanadi:
 *   tokenable_type = "App\Models\AdminUser"
 *
 * Tenant endpointlar (routes/api.php) ham `auth:sanctum` ishlatadi, lekin
 * ular tenant schema'dagi tokens jadvalini qidiradi — admin token u yerda
 * topilmaydi. Shuning uchun cross-context kirish avto-bloklanadi.
 */
class AdminUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Jadval nomi — default `admin_users`.
     */
    protected $table = 'admin_users';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
