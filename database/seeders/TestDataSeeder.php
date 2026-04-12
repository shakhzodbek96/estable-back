<?php

namespace Database\Seeders;

use App\Enums\InventoryStatus;
use App\Models\Accessory;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Investor;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();
        $shop = Shop::first();
        $investor = Investor::first();

        // Partnerlar yaratish
        if (Partner::count() === 0) {
            Partner::create(['name' => 'Ali aka do\'koni', 'phone' => '901234567', 'address' => 'Chilonzor, Toshkent', 'balance' => 0, 'created_by' => $admin->id]);
            Partner::create(['name' => 'Bekzod Mobile', 'phone' => '907654321', 'address' => 'Sergeli, Toshkent', 'balance' => 0, 'created_by' => $admin->id]);
            Partner::create(['name' => 'Sardor Electronics', 'phone' => '935551234', 'address' => 'Yunusobod, Toshkent', 'balance' => 0, 'created_by' => $admin->id]);
            $this->command->info('3 ta partner yaratildi');
        }

        // Kurs
        if (Rate::count() === 0) {
            Rate::create(['rate' => 12850.00, 'created_by' => $admin->id]);
        }

        // Qo'shimcha serial tovarlar (turli kategoriyalardan)
        $categories = Category::all();
        $products = Product::where('type', 'serial')->take(10)->get();

        if ($products->count() > 0 && Inventory::where('status', 'in_stock')->count() < 20) {
            $imeiBase = 35000000000000;
            $count = 0;

            foreach ($products->take(5) as $product) {
                for ($i = 0; $i < 3; $i++) {
                    $imei = $imeiBase + rand(1000000, 9999999);
                    $purchasePrice = rand(300, 1200);

                    Inventory::create([
                        'product_id' => $product->id,
                        'serial_number' => (string) $imei,
                        'purchase_price' => $purchasePrice,
                        'extra_cost' => 0,
                        'selling_price' => $purchasePrice + rand(50, 200),
                        'status' => InventoryStatus::InStock,
                        'has_box' => rand(0, 1),
                        'state' => rand(0, 3) === 0 ? 'used' : 'new',
                        'shop_id' => $shop->id,
                        'investor_id' => rand(0, 1) ? $investor?->id : null,
                        'created_by' => $admin->id,
                    ]);
                    $count++;
                }
            }

            $this->command->info("{$count} ta serial tovar qo'shildi");
        }

        // Qo'shimcha aksessuarlar
        $bulkProducts = Product::where('type', 'bulk')->take(8)->get();

        if ($bulkProducts->count() > 0 && Accessory::count() < 10) {
            $count = 0;

            foreach ($bulkProducts as $product) {
                $barcode = 'ACC' . str_pad($product->id, 5, '0', STR_PAD_LEFT) . rand(100, 999);
                $purchasePrice = rand(2, 50);

                Accessory::create([
                    'product_id' => $product->id,
                    'invoice_number' => 'INV-TEST-' . rand(1000, 9999),
                    'barcode' => $barcode,
                    'quantity' => rand(20, 100),
                    'sold_quantity' => 0,
                    'consigned_quantity' => 0,
                    'purchase_price' => $purchasePrice,
                    'sell_price' => $purchasePrice + rand(3, 20),
                    'shop_id' => $shop->id,
                    'investor_id' => rand(0, 1) ? $investor?->id : null,
                    'is_active' => true,
                    'created_by' => $admin->id,
                ]);
                $count++;
            }

            $this->command->info("{$count} ta aksessuar qo'shildi");
        }

        $this->command->info('Test ma\'lumotlar yaratildi!');
        $this->command->info('Inventories (in_stock): ' . Inventory::where('status', 'in_stock')->count());
        $this->command->info('Accessories (active): ' . Accessory::where('is_active', true)->count());
        $this->command->info('Partners: ' . Partner::count());
    }
}
