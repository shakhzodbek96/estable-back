<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

/**
 * Central DB uchun asosiy seeder (public schema).
 *
 * Bu seeder faqat `php artisan db:seed --class=CentralDatabaseSeeder`
 * bilan chaqiriladi — tenant seederidan (DatabaseSeeder) alohida.
 *
 * Default super admin:
 *   username: admin
 *   password: admin
 *
 * Production'da birinchi login'dan so'ng parolni o'zgartirish kerak.
 */
class CentralDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Super Administrator',
                'email' => 'admin@estable.uz',
                'password' => 'admin',
                'is_active' => true,
            ]
        );
    }
}
