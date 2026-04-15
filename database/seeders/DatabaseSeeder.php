<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Rate;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrator',
                'phone' => null,
                'is_blocked' => false,
                'must_change_password' => false,
                'role' => UserRole::Admin,
                'password' => 'admin123',
            ]
        );

        $shop = Shop::updateOrCreate(
            ['name' => 'Asosiy do\'kon'],
            ['created_by' => $admin->id]
        );

        // Admin'ni asosiy do'konga biriktirish
        if (!$admin->shop_id) {
            $admin->update(['shop_id' => $shop->id]);
        }

        // Boshlang'ich valyuta kursi (agar yo'q bo'lsa)
        if (!Rate::exists()) {
            Rate::create([
                'rate' => 12850.00,
                'created_by' => $admin->id,
            ]);
        }

        $this->call([
            CategorySeeder::class,
            ProductSeeder::class,
        ]);
    }
}
