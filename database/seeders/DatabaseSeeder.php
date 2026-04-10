<?php

namespace Database\Seeders;

use App\Enums\UserRole;
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

        Shop::updateOrCreate(
            ['name' => 'Asosiy do\'kon'],
            ['created_by' => $admin->id]
        );

        $this->call([
            CategorySeeder::class,
            ProductSeeder::class,
        ]);
    }
}
