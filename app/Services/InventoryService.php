<?php

namespace App\Services;

use App\Enums\AttributeScope;
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
    public function __construct(
        private AttributeService $attributes
    ) {}

    public function createBatch(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            $inventories = collect();

            $totalExtraCost = 0;
            foreach ($data['serials'] as $serial) {
                $serialExtraCost = (float) ($serial['extra_cost'] ?? 0);
                $totalExtraCost += $serialExtraCost;

                // Dinamik xususiyatlar — har bir serial uchun alohida snapshot
                $customAttributes = $this->attributes->snapshot($serial['custom_attributes'] ?? null, AttributeScope::Serial);

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
                    'custom_attributes' => $customAttributes,
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'] ?? null,
                    'created_by' => auth()->id(),
                ]));
            }

            // Har qanday tovar kirimi xarajat sifatida Transaction (type=Purchase) yaratadi.
            // Investor tanlangan bo'lsa — qo'shimcha Investment + investor.balance kamayadi.
            // Investor tanlanmagan bo'lsa — do'kon xarajati (investor_id = null, shop_id orqali ajraladi).
            $totalCost = $data['purchase_price'] * count($data['serials']) + $totalExtraCost;

            if ($totalCost > 0) {
                $rate = Rate::current();
                $investorId = !empty($data['investor_id']) ? $data['investor_id'] : null;

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
                        'funded_by' => $investorId ? 'investor' : 'shop',
                    ],
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $investorId,
                    'created_by' => auth()->id(),
                    'accepted_by' => auth()->id(),
                ]);

                if ($investorId) {
                    Investment::create([
                        'investor_id' => $investorId,
                        'transaction_id' => $transaction->id,
                        'type' => InvestmentType::BuyingProduct,
                        'is_credit' => false,
                        'amount' => $totalCost,
                        'rate' => $rate?->rate ?? 0,
                        'comment' => "Tovar kiritildi: " . count($data['serials']) . " dona",
                        'created_by' => auth()->id(),
                    ]);

                    $investor = Investor::lockForUpdate()->find($investorId);
                    $investor->decrement('balance', $totalCost);
                }
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
            // Client'dan kelgan [{id,value}] ni saqlanadigan snapshot'ga aylantiramiz
            if (array_key_exists('custom_attributes', $data)) {
                $data['custom_attributes'] = $this->attributes->snapshot($data['custom_attributes'], AttributeScope::Serial);
            }

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

    /**
     * Inventory tovarni o'chirish. Investor mablag'iga olingan bo'lsa — xaridni teskari
     * hisoblaymiz: kapital investor balansiga qaytadi, bog'liq Purchase Transaction/Investment
     * summasi kamayadi (oxirgi tovar bo'lsa — butunlay o'chiriladi). Aks holda balans drift bo'ladi.
     */
    public function deleteItem(Inventory $inventory): void
    {
        DB::transaction(function () use ($inventory) {
            if ($inventory->investor_id) {
                $contribution = (float) $inventory->purchase_price + (float) $inventory->extra_cost;
                $this->reverseInventoryPurchase($inventory, $contribution);
            }
            $inventory->delete();
        });
    }

    private function reverseInventoryPurchase(Inventory $inventory, float $contribution): void
    {
        // Kapitalni investor balansiga qaytaramiz
        Investor::where('id', $inventory->investor_id)->lockForUpdate()->increment('balance', $contribution);

        $transaction = Transaction::where('type', TransactionType::Purchase)
            ->where('investor_id', $inventory->investor_id)
            ->whereJsonContains('details->inventory_ids', $inventory->id)
            ->first();

        if (!$transaction) {
            return; // bog'liq tranzaksiya topilmadi — kamida balans to'g'rilandi
        }

        $ids = collect($transaction->details['inventory_ids'] ?? [])
            ->reject(fn ($id) => (int) $id === (int) $inventory->id)
            ->values()->all();
        $newAmount = (float) $transaction->amount - $contribution;

        if (empty($ids) || $newAmount <= 0.001) {
            Investment::where('transaction_id', $transaction->id)->delete();
            $transaction->delete();
            return;
        }

        $details = $transaction->details;
        $details['inventory_ids'] = $ids;
        $transaction->details = $details;
        $transaction->amount = $newAmount;
        $transaction->save();

        Investment::where('transaction_id', $transaction->id)->get()->each(function (Investment $inv) use ($contribution) {
            $inv->amount = (float) $inv->amount - $contribution;
            $inv->save();
        });
    }

    /**
     * Rich import — har qatorda alohida tovar/narx/holat bo'ladigan import.
     *
     * Shared maydonlar: shop_id, investor_id (ixtiyoriy)
     * Per-row: product_id, purchase_price, selling_price, wholesale_price?, state, has_box, serial_number, extra_serial_number?, notes?
     *
     * Investor biriktirilgan bo'lsa — barcha qatorlar summasi bitta Transaction + Investment yozuviga
     * birlashtiriladi (alohida ko'p yozuv emas, audit toza qoladi).
     *
     * Hammasi DB::transaction ichida — yoki barchasi, yoki hech biri.
     *
     * @return Collection<int, Inventory>
     */
    public function createRichBatch(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            $inventories = collect();
            $totalCost = 0.0;

            foreach ($data['rows'] as $row) {
                $inv = Inventory::create([
                    'product_id' => $row['product_id'],
                    'serial_number' => $row['serial_number'],
                    'extra_serial_number' => $row['extra_serial_number'] ?? null,
                    'purchase_price' => $row['purchase_price'],
                    'extra_cost' => 0,
                    'selling_price' => $row['selling_price'],
                    'wholesale_price' => $row['wholesale_price'] ?? null,
                    'status' => InventoryStatus::InStock,
                    'has_box' => $row['has_box'] ?? true,
                    'state' => $row['state'] ?? 'new',
                    'notes' => $row['notes'] ?? null,
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'] ?? null,
                    'created_by' => auth()->id(),
                ]);
                $inventories->push($inv);
                $totalCost += (float) $row['purchase_price'];
            }

            if ($totalCost > 0) {
                $rate = Rate::current();
                $investorId = !empty($data['investor_id']) ? $data['investor_id'] : null;

                $transaction = Transaction::create([
                    'amount' => $totalCost,
                    'currency' => 'usd',
                    'rate' => $rate?->rate ?? 0,
                    'is_credit' => false,
                    'type' => TransactionType::Purchase,
                    'transaction_date' => now()->toDateString(),
                    'details' => [
                        'inventory_ids' => $inventories->pluck('id')->all(),
                        'serial_count' => $inventories->count(),
                        'source' => 'rich_import',
                        'funded_by' => $investorId ? 'investor' : 'shop',
                    ],
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $investorId,
                    'created_by' => auth()->id(),
                    'accepted_by' => auth()->id(),
                ]);

                if ($investorId) {
                    Investment::create([
                        'investor_id' => $investorId,
                        'transaction_id' => $transaction->id,
                        'type' => InvestmentType::BuyingProduct,
                        'is_credit' => false,
                        'amount' => $totalCost,
                        'rate' => $rate?->rate ?? 0,
                        'comment' => "Импорт: {$inventories->count()} ед.",
                        'created_by' => auth()->id(),
                    ]);

                    $investor = Investor::lockForUpdate()->find($investorId);
                    $investor->decrement('balance', $totalCost);
                }
            }

            return $inventories;
        });
    }

    public function addRepairCost(Inventory $inventory, array $data): RepairCost
    {
        // Remont xarajatini faqat «ta'mirda» turgan tovarga qo'shish mumkin — aks holda
        // yopilgan sotuvning COGS'i (extra_cost) retroaktiv buzilardi va investor balansi
        // hech qanday asossiz kamayardi (sendToRepair/returnFromRepair guard'lariga monand).
        if ($inventory->status !== InventoryStatus::InRepair) {
            throw new \InvalidArgumentException(
                'Ремонтные расходы можно добавить только товару в статусе «в ремонте».'
            );
        }

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

            $transaction = Transaction::create([
                'amount' => $data['amount'],
                'currency' => 'usd',
                'rate' => $rate?->rate ?? 0,
                'is_credit' => false,
                'type' => TransactionType::Repair,
                'transaction_date' => now()->toDateString(),
                'shop_id' => $inventory->shop_id,
                'investor_id' => $inventory->investor_id,
                'created_by' => auth()->id(),
                'accepted_by' => auth()->id(),
                'details' => [
                    'inventory_id' => $inventory->id,
                    'repair_cost_id' => $cost->id,
                ],
            ]);

            // Investor mablag'iga olingan tovar bo'lsa — remont kapitalini balansdan chiqaramiz
            // VA parity (balance == SUM(investments)) uchun Investment yozuvi yaratamiz —
            // boshqa barcha balans-o'zgartiruvchi yo'llar kabi.
            if ($inventory->investor_id) {
                Investment::create([
                    'investor_id' => $inventory->investor_id,
                    'transaction_id' => $transaction->id,
                    'type' => InvestmentType::BuyingProduct,
                    'is_credit' => false,
                    'amount' => $data['amount'],
                    'rate' => $rate?->rate ?? 0,
                    'comment' => "Ремонт #{$cost->id}",
                    'created_by' => auth()->id(),
                ]);

                Investor::where('id', $inventory->investor_id)
                    ->lockForUpdate()
                    ->decrement('balance', $data['amount']);
            }

            return $cost;
        });
    }
}
