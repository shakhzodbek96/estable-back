<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get categories (aligned with CategorySeeder)
        $iphoneCategory = Category::where('name', 'Iphones')->first();
        $samsungCategory = Category::where('name', 'Samsungs')->first();
        $otherSmartphonesCategory = Category::where('name', 'Smartphones')->first();
        $macbookCategory = Category::where('name', 'MacBooks')->first();

        // iPhone line (popular in Uzbekistan, 2024-2026 models) with storage + color
        $iphones = [
            'iPhone 17 Pro Max 256GB Cosmic Orange',
            'iPhone 17 Pro Max 512GB Cosmic Orange',
            'iPhone 17 Pro Max 1TB Cosmic Orange',
            'iPhone 17 Pro 256GB Deep Blue',
            'iPhone 17 Pro 512GB Deep Blue',
            'iPhone 17 Pro 1TB Deep Blue',
            'iPhone 17 Air 256GB Space Black',
            'iPhone 17 Air 512GB Space Black',
            'iPhone 16 Pro Max 256GB Desert Titanium',
            'iPhone 16 Pro Max 512GB Desert Titanium',
            'iPhone 16 Pro Max 1TB Desert Titanium',
            'iPhone 16 Pro 256GB Natural Titanium',
            'iPhone 16 Pro 512GB Natural Titanium',
            'iPhone 16 Pro 1TB Natural Titanium',
            'iPhone 16 Plus 128GB Blue',
            'iPhone 16 Plus 256GB Blue',
            'iPhone 16 128GB Starlight',
            'iPhone 16 256GB Starlight',
            'iPhone 15 Pro Max 256GB Natural Titanium',
            'iPhone 15 Pro Max 512GB Natural Titanium',
            'iPhone 15 Pro Max 1TB Natural Titanium',
            'iPhone 15 Pro 256GB Blue Titanium',
            'iPhone 15 Pro 512GB Blue Titanium',
            'iPhone 15 Pro 1TB Blue Titanium',
            'iPhone 15 Plus 128GB Midnight',
            'iPhone 15 Plus 256GB Midnight',
            'iPhone 15 128GB Pink',
            'iPhone 15 256GB Pink',
            'iPhone 14 Pro Max 256GB Deep Purple',
            'iPhone 14 Pro Max 512GB Deep Purple',
            'iPhone 14 Pro Max 1TB Deep Purple',
            'iPhone 14 Plus 128GB Starlight',
            'iPhone 14 Plus 256GB Starlight',
            'iPhone 13 128GB Midnight',
            'iPhone 13 256GB Midnight',
            'iPhone SE (3rd gen) 128GB Midnight',
            'iPhone SE (3rd gen) 256GB Midnight',
        ];

        // Samsung Galaxy line (flagships, foldables, A/M budget-midrange) with storage + color
        $samsungPhones = [
            'Samsung Galaxy S25 Ultra 256GB Titanium Black',
            'Samsung Galaxy S25 Ultra 512GB Titanium Black',
            'Samsung Galaxy S25 Ultra 1TB Titanium Black',
            'Samsung Galaxy S25+ 256GB Ice Blue',
            'Samsung Galaxy S25+ 512GB Ice Blue',
            'Samsung Galaxy S25 256GB Sandstone Pink',
            'Samsung Galaxy S25 512GB Sandstone Pink',
            'Samsung Galaxy S24 Ultra 256GB Titanium Gray',
            'Samsung Galaxy S24 Ultra 512GB Titanium Gray',
            'Samsung Galaxy S24 Ultra 1TB Titanium Gray',
            'Samsung Galaxy S24+ 256GB Onyx Black',
            'Samsung Galaxy S24+ 512GB Onyx Black',
            'Samsung Galaxy S24 128GB Amber Yellow',
            'Samsung Galaxy S24 256GB Amber Yellow',
            'Samsung Galaxy S23 FE 128GB Mint',
            'Samsung Galaxy S23 FE 256GB Mint',
            'Samsung Galaxy Z Fold 6 512GB Navy',
            'Samsung Galaxy Z Fold 6 1TB Navy',
            'Samsung Galaxy Z Flip 6 256GB Mint',
            'Samsung Galaxy Z Flip 6 512GB Mint',
            'Samsung Galaxy Z Fold 5 512GB Phantom Black',
            'Samsung Galaxy Z Fold 5 1TB Phantom Black',
            'Samsung Galaxy Z Flip 5 256GB Lavender',
            'Samsung Galaxy Z Flip 5 512GB Lavender',
            'Samsung Galaxy A55 5G 128GB Awesome Navy',
            'Samsung Galaxy A55 5G 256GB Awesome Navy',
            'Samsung Galaxy A35 5G 128GB Awesome Iceblue',
            'Samsung Galaxy A35 5G 256GB Awesome Iceblue',
            'Samsung Galaxy A25 128GB Blue Black',
            'Samsung Galaxy A25 256GB Blue Black',
            'Samsung Galaxy A15 128GB Black',
            'Samsung Galaxy A15 256GB Black',
            'Samsung Galaxy A05 64GB Silver',
            'Samsung Galaxy A05 128GB Silver',
            'Samsung Galaxy M55 5G 128GB Graphite Gray',
            'Samsung Galaxy M55 5G 256GB Graphite Gray',
            'Samsung Galaxy M35 5G 128GB Ocean Blue',
            'Samsung Galaxy M35 5G 256GB Ocean Blue',
            'Samsung Galaxy M15 5G 128GB Light Blue',
            'Samsung Galaxy M15 5G 256GB Light Blue',
        ];

        // Other popular smartphones in Uzbekistan (Xiaomi, Poco, Huawei, Honor, Realme, Tecno, Infinix, OnePlus, Google, Vivo, Oppo, Motorola, Nothing)
        $otherSmartphones = [
            // Xiaomi / Redmi / Poco
            'Xiaomi 15 Ultra 512GB Black',
            'Xiaomi 15 Ultra 1TB White',
            'Xiaomi 15 Pro 256GB Blue',
            'Xiaomi 15 Pro 512GB Blue',
            'Xiaomi 14 Pro 256GB Ceramic Black',
            'Xiaomi 14 Pro 512GB Ceramic Black',
            'Xiaomi 14 256GB Snow Mountain White',
            'Redmi Note 14 Pro+ 5G 256GB Midnight Black',
            'Redmi Note 14 Pro+ 5G 512GB Midnight Black',
            'Redmi Note 14 Pro 5G 256GB Aurora Purple',
            'Redmi Note 14 5G 128GB Ice Blue',
            'Redmi Note 14 5G 256GB Ice Blue',
            'Redmi Note 13 Pro+ 5G 256GB Mystic Silver',
            'Redmi Note 13 Pro+ 5G 512GB Mystic Silver',
            'Redmi Note 13 Pro 5G 256GB Forest Green',
            'Redmi Note 13 Pro 5G 512GB Forest Green',
            'Redmi 13C 128GB Ocean Blue',
            'Redmi 13C 256GB Ocean Blue',
            'Poco F6 Pro 256GB Black',
            'Poco F6 Pro 512GB Black',
            'Poco F6 256GB Yellow',
            'Poco X7 Pro 256GB Black',
            'Poco X7 Pro 512GB Black',
            'Poco X7 256GB Blue',
            'Poco X6 Pro 256GB Racing Gray',
            'Poco X6 Pro 512GB Racing Gray',
            'Poco X6 128GB Ice Silver',
            'Poco X6 256GB Ice Silver',
            'Poco M6 Pro 128GB Forest Green',
            'Poco M6 Pro 256GB Forest Green',

            // Realme
            'Realme GT 7 Pro 256GB Nebula Silver',
            'Realme GT 7 Pro 512GB Nebula Silver',
            'Realme 14 Pro+ 5G 256GB Oasis Green',
            'Realme 14 Pro 5G 256GB Oasis Green',
            'Realme 13 Pro+ 5G 256GB Astral Black',
            'Realme 12 Pro+ 5G 256GB Submarine Blue',

            // Huawei / Honor
            'Huawei Pura 70 Ultra 512GB Green',
            'Huawei Pura 70 Pro 512GB Black',
            'Huawei Mate 60 Pro 512GB Gray',
            'Huawei Nova 12 SE 256GB Sky Blue',
            'Honor Magic6 Pro 512GB Epi Green',
            'Honor 200 Pro 512GB Black',
            'Honor X9b 256GB Sunrise Orange',

            // Oppo / Vivo / OnePlus
            'Oppo Reno 13 Pro 5G 256GB Pearl Pink',
            'Oppo Reno 13 Pro 5G 512GB Pearl Pink',
            'Vivo X200 Pro 512GB Blue',
            'Vivo V40 256GB Green',
            'OnePlus 13 256GB Emerald Dawn',
            'OnePlus 13 512GB Emerald Dawn',
            'OnePlus 12R 256GB Iron Gray',

            // Google
            'Google Pixel 9 Pro 256GB Obsidian',
            'Google Pixel 9 Pro 512GB Obsidian',
            'Google Pixel 9 256GB Porcelain',
            'Google Pixel 8a 128GB Obsidian',
            'Google Pixel 8a 256GB Obsidian',

            // Tecno / Infinix
            'Tecno Camon 30 Premier 5G 256GB Alps Snowy Silver',
            'Tecno Camon 30 Pro 5G 256GB Basaltic Dark',
            'Tecno Pova 6 Neo 256GB Comet Green',
            'Infinix Note 40 Pro+ 256GB Vintage Green',
            'Infinix GT 20 Pro 256GB Mecha Silver',

            // Motorola / Nothing
            'Motorola Edge 50 Ultra 256GB Forest Gray',
            'Motorola Edge 50 Ultra 512GB Forest Gray',
            'Nothing Phone 2a Plus 256GB White',
        ];

        // MacBook laptops with full configurations for ERP product naming
        $macbooks = [
            'MacBook Pro 16" (2023) M3 Max 16‑CPU/40‑GPU 64GB 2TB SSD Space Black',
            'MacBook Pro 16" (2023) M3 Pro 12‑CPU/18‑GPU 36GB 1TB SSD Space Black',
            'MacBook Pro 14" (2023) M3 Pro 12‑CPU/18‑GPU 32GB 1TB SSD Space Black',
            'MacBook Pro 14" (2023) M3 8‑CPU/10‑GPU 16GB 512GB SSD Silver',
            'MacBook Air 15" (2024) M3 8‑CPU/10‑GPU 16GB 512GB SSD Midnight',
            'MacBook Air 13" (2024) M3 8‑CPU/10‑GPU 16GB 512GB SSD Starlight',
            'MacBook Air 13" (2022) M2 8‑CPU/10‑GPU 8GB 256GB SSD Space Gray',
            'MacBook Pro 13" (2022) M2 8‑CPU/10‑GPU 16GB 512GB SSD Space Gray',
        ];

        // Insert iPhones
        foreach ($iphones as $phone) {
            Product::firstOrCreate(
                ['name' => $phone],
                ['category_id' => $iphoneCategory?->id, 'type' => 'serial']
            );
        }

        // Insert Samsung phones
        foreach ($samsungPhones as $phone) {
            Product::firstOrCreate(
                ['name' => $phone],
                ['category_id' => $samsungCategory?->id, 'type' => 'serial']
            );
        }

        // Insert other smartphones (non-Apple/Samsung)
        foreach ($otherSmartphones as $phone) {
            Product::firstOrCreate(
                ['name' => $phone],
                ['category_id' => $otherSmartphonesCategory?->id, 'type' => 'serial']
            );
        }

        // Insert MacBooks
        foreach ($macbooks as $mac) {
            Product::firstOrCreate(
                ['name' => $mac],
                ['category_id' => $macbookCategory?->id, 'type' => 'serial']
            );
        }
    }
}
