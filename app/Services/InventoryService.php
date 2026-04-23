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

            $totalExtraCost = 0;
            foreach ($data['serials'] as $serial) {
                $serialExtraCost = (float) ($serial['extra_cost'] ?? 0);
                $totalExtraCost += $serialExtraCost;

                $inventories->push(Inventory::create([
                    'product_id' => $data['product_id'],
                    'serial_number' => $serial['serial_number'],
                    'extra_serial_number' => $serial['extra_serial_number'] ?? null,
                    'purchase_price' => $data['purchase_price'],
                    'extra_cost' => $serialExtraCost,
                    'selling_price' => $data['selling_price'],
                    'wholesale_price' => $data['wholesale_price'] ?? null,
                    'status' => InventoryStatus::InStock,
                    'has_box' => $data['has_box'] ?? true,
                    'state' => $data['state'] ?? 'new',
                    'notes' => $serial['notes'] ?? ($data['notes'] ?? null),
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'] ?? null,
                    'created_by' => auth()->id(),
                ]));
            }

            if (!empty($data['investor_id'])) {
                $totalCost = $data['purchase_price'] * count($data['serials']) + $totalExtraCost;
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

    /**
     * Inventory (serial) tovarni tahrirlash.
     *
     * Agar purchase_price yoki extra_cost o'zgarsa VA tovar investor mablag'i evaziga
     * olingan bo'lsa — bog'liq Transaction, Investment va investor.balance avtomatik
     * delta bilan to'g'rilab qo'yiladi.
     *
     * Hammasi bitta DB transaction ichida — yoki hammasi, yoki hech biri.
     */
    public function updateItem(Inventory $inventory, array $data): Inventory
    {
        return DB::transaction(function () use ($inventory, $data) {
            $oldContribution = (float) $inventory->purchase_price + (float) $inventory->extra_cost;

            $inventory->update($data);
            $inventory->refresh();

            $newContribution = (float) $inventory->purchase_price + (float) $inventory->extra_cost;
            $delta = $newContribution - $oldContribution;

            if ($inventory->investor_id && abs($delta) > 0.001) {
                $this->cascadeInventoryPurchaseDelta($inventory, $delta);
            }

            return $inventory;
        });
    }

    /**
     * Inventory uchun purchase delta'ni tegishli Transaction/Investment/investor.balance'ga
     * surib qo'yadi. Transaction — bitta batch bir nechta inventory yaratishi mumkin
     * (details->inventory_ids massiv), shunda ham ishlaydi (amount to'liq summa).
     */
    private function cascadeInventoryPurchaseDelta(Inventory $inventory, float $delta): void
    {
        $transaction = Transaction::where('type', TransactionType::Purchase)
            ->where('investor_id', $inventory->investor_id)
            ->whereJsonContains('details->inventory_ids', $inventory->id)
            ->first();

        if (!$transaction) {
            // Transaction topilmasa — cascade qilmaymiz. Investorga noto'g'ri balans
            // tushmasligi uchun ishda ham xavfsiz.
            return;
        }

        $transaction->amount = (float) $transaction->amount + $delta;
        $transaction->save();

        Investment::where('transaction_id', $transaction->id)
            ->get()
            ->each(function (Investment $inv) use ($delta) {
                $inv->amount = (float) $inv->amount + $delta;
                $inv->save();
            });

        $investor = Investor::lockForUpdate()->find($inventory->investor_id);
        if ($investor) {
            $investor->balance = (float) $investor->balance - $delta;
            $investor->save();
        }
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
