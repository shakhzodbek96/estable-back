<?php

namespace App\Services;

use App\Enums\InvestmentType;
use App\Models\Accessory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use Illuminate\Support\Facades\DB;

class AccessoryService
{
    public function createBatch(array $data): Accessory
    {
        return DB::transaction(function () use ($data) {
            $accessory = Accessory::create([
                'product_id' => $data['product_id'],
                'invoice_number' => $data['invoice_number'],
                'barcode' => $data['barcode'],
                'quantity' => $data['quantity'],
                'sold_quantity' => 0,
                'consigned_quantity' => 0,
                'purchase_price' => $data['purchase_price'],
                'sell_price' => $data['sell_price'],
                'notes' => $data['notes'] ?? null,
                'shop_id' => $data['shop_id'],
                'investor_id' => $data['investor_id'] ?? null,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            if (!empty($data['investor_id'])) {
                $totalCost = $data['purchase_price'] * $data['quantity'];
                $rate = Rate::current();

                Investment::create([
                    'investor_id' => $data['investor_id'],
                    'type' => InvestmentType::BuyingProduct,
                    'is_credit' => false,
                    'amount' => $totalCost,
                    'rate' => $rate?->rate ?? 0,
                    'comment' => "Aksessuar partiyasi: {$data['barcode']}",
                    'created_by' => auth()->id(),
                ]);

                $investor = Investor::lockForUpdate()->find($data['investor_id']);
                $investor->decrement('balance', $totalCost);
            }

            return $accessory;
        });
    }

    public function findForSale(string $barcode, int $shopId): ?Accessory
    {
        return Accessory::where('barcode', $barcode)
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->whereRaw('quantity - sold_quantity - consigned_quantity > 0')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    public function restock(Accessory $accessory, int $quantity): Accessory
    {
        return DB::transaction(function () use ($accessory, $quantity) {
            $accessory->increment('quantity', $quantity);

            if (!$accessory->is_active) {
                $accessory->update(['is_active' => true]);
            }

            return $accessory->fresh();
        });
    }
}
