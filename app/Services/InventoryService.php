<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\InvestmentType;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use App\Models\RepairCost;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function createBatch(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            $inventories = collect();

            foreach ($data['serials'] as $serial) {
                $inventories->push(Inventory::create([
                    'product_id' => $data['product_id'],
                    'serial_number' => $serial['serial_number'],
                    'extra_serial_number' => $serial['extra_serial_number'] ?? null,
                    'purchase_price' => $data['purchase_price'],
                    'extra_cost' => 0,
                    'selling_price' => $data['selling_price'],
                    'status' => InventoryStatus::InStock,
                    'has_box' => $data['has_box'] ?? true,
                    'state' => $data['state'] ?? 'new',
                    'notes' => $data['notes'] ?? null,
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'] ?? null,
                    'created_by' => auth()->id(),
                ]));
            }

            if (!empty($data['investor_id'])) {
                $totalCost = $data['purchase_price'] * count($data['serials']);
                $rate = Rate::current();

                $transaction = Transaction::create([
                    'amount' => $totalCost,
                    'currency' => 'usd',
                    'rate' => $rate?->rate ?? 0,
                    'is_credit' => false,
                    'type' => TransactionType::Purchase,
                    'transaction_date' => now()->toDateString(),
                    'details' => [
                        'inventory_ids' => $inventories->pluck('id')->all(),
                        'product_id' => $data['product_id'],
                        'serial_count' => count($data['serials']),
                    ],
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'],
                    'created_by' => auth()->id(),
                    'accepted_by' => auth()->id(),
                ]);

                Investment::create([
                    'investor_id' => $data['investor_id'],
                    'transaction_id' => $transaction->id,
                    'type' => InvestmentType::BuyingProduct,
                    'is_credit' => false,
                    'amount' => $totalCost,
                    'rate' => $rate?->rate ?? 0,
                    'comment' => "Tovar kiritildi: " . count($data['serials']) . " dona",
                    'created_by' => auth()->id(),
                ]);

                $investor = Investor::lockForUpdate()->find($data['investor_id']);
                $investor->decrement('balance', $totalCost);
            }

            return $inventories;
        });
    }

    public function addRepairCost(Inventory $inventory, array $data): RepairCost
    {
        return DB::transaction(function () use ($inventory, $data) {
            $cost = RepairCost::create([
                'inventory_id' => $inventory->id,
                'return_id' => $data['return_id'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'repaired_by' => $data['repaired_by'] ?? null,
                'repaired_at' => $data['repaired_at'] ?? now(),
                'created_by' => auth()->id(),
            ]);

            $inventory->increment('extra_cost', $data['amount']);

            $rate = Rate::current();

            Transaction::create([
                'amount' => $data['amount'],
                'currency' => 'usd',
                'rate' => $rate?->rate ?? 0,
                'is_credit' => false,
                'type' => TransactionType::Repair,
                'shop_id' => $inventory->shop_id,
                'investor_id' => $inventory->investor_id,
                'created_by' => auth()->id(),
                'accepted_by' => auth()->id(),
                'details' => [
                    'inventory_id' => $inventory->id,
                    'repair_cost_id' => $cost->id,
                ],
            ]);

            if ($inventory->investor_id) {
                $investor = Investor::lockForUpdate()->find($inventory->investor_id);
                $investor->decrement('balance', $data['amount']);
            }

            return $cost;
        });
    }
}
