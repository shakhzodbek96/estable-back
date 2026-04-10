<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Iphones',
                'description' => 'Apple iPhone line for Uzbekistan market',
                'is_active' => true,
            ],
            [
                'name' => 'Samsungs',
                'description' => 'Samsung Galaxy smartphones and foldables',
                'is_active' => true,
            ],
            [
                'name' => 'Smartphones',
                'description' => 'Other popular smartphone brands (Xiaomi, Poco, Huawei, etc.)',
                'is_active' => true,
            ],
            [
                'name' => 'MacBooks',
                'description' => 'Apple MacBook laptops sold locally',
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
