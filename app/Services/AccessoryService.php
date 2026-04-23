<?php

namespace App\Services;

use App\Enums\InvestmentType;
use App\Enums\TransactionType;
use App\Models\Accessory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccessoryService
{
    /**
     * Bir nechta partiyani bitta so'rov bilan yaratish.
     *
     * Har partiya alohida Accessory record yaratadi.
     * Agar investor tanlangan bo'lsa — partiyalar summasi bitta Transaction/Investment
     * yozuvi bilan investor balansidan chegirib qo'yiladi (optimallashtirish, N ta emas).
     *
     * Barcha operatsiyalar bitta DB transaction ichida — yoki hammasi muvaffaqiyatli,
     * yoki hech biri (rollback). Validatsiyadan o'tmagan unique barcode xatosi
     * avvalroq tushadi (StoreAccessoryRequest / BulkStoreAccessoryRequest).
     */
    public function createBulkBatches(array $data): Collection
    {
        return DB::transaction(function () use ($data) {
            $accessories = collect();
            $totalCost = 0.0;

            foreach ($data['batches'] as $batch) {
                $accessory = Accessory::create([
                    'product_id' => $data['product_id'],
                    'invoice_number' => $data['invoice_number'],
                    'barcode' => $batch['barcode'],
                    'quantity' => $batch['quantity'],
                    'sold_quantity' => 0,
                    'consigned_quantity' => 0,
                    'purchase_price' => $batch['purchase_price'],
                    'sell_price' => $batch['sell_price'],
                    'wholesale_price' => $batch['wholesale_price'] ?? null,
                    'notes' => $batch['notes'] ?? null,
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'] ?? null,
                    'is_active' => true,
                    'created_by' => auth()->id(),
                ]);

                $accessories->push($accessory);
                $totalCost += (float) $batch['purchase_price'] * (int) $batch['quantity'];
            }

            if (!empty($data['investor_id']) && $totalCost > 0) {
                $rate = Rate::current();

                $transaction = Transaction::create([
                    'amount' => $totalCost,
                    'currency' => 'usd',
                    'rate' => $rate?->rate ?? 0,
                    'is_credit' => false,
                    'type' => TransactionType::Purchase,
                    'transaction_date' => now()->toDateString(),
                    'details' => [
                        'accessory_ids' => $accessories->pluck('id')->all(),
                        'invoice_number' => $data['invoice_number'],
                        'batches_count' => $accessories->count(),
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
                    'comment' => "Aksessuar partiyalari: {$accessories->count()} ta (накл. {$data['invoice_number']})",
                    'created_by' => auth()->id(),
                ]);

                $investor = Investor::lockForUpdate()->find($data['investor_id']);
                $investor->decrement('balance', $totalCost);
            }

            return $accessories;
        });
    }

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
                'wholesale_price' => $data['wholesale_price'] ?? null,
                'notes' => $data['notes'] ?? null,
                'shop_id' => $data['shop_id'],
                'investor_id' => $data['investor_id'] ?? null,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            if (!empty($data['investor_id'])) {
                $totalCost = $data['purchase_price'] * $data['quantity'];
                $rate = Rate::current();

                $transaction = Transaction::create([
                    'amount' => $totalCost,
                    'currency' => 'usd',
                    'rate' => $rate?->rate ?? 0,
                    'is_credit' => false,
                    'type' => TransactionType::Purchase,
                    'transaction_date' => now()->toDateString(),
                    'details' => [
                        'accessory_id' => $accessory->id,
                        'barcode' => $data['barcode'],
                        'quantity' => $data['quantity'],
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
                    'comment' => "Aksessuar partiyasi: {$data['barcode']}",
                    'created_by' => auth()->id(),
                ]);

                $investor = Investor::lockForUpdate()->find($data['investor_id']);
                $investor->decrement('balance', $totalCost);
            }

            return $accessory;
        });
    }

    /**
     * Aksessuar partiyasini tahrirlash.
     *
     * Agar purchase_price yoki quantity o'zgarsa VA investor biriktirilgan bo'lsa —
     * bog'liq Transaction, Investment, investor.balance delta bilan to'g'rilab qo'yiladi.
     *
     * Quantity allaqachon sotilgan/konsignatsiyadagi miqdordan kam bo'lishi mumkin emas
     * — bunday bo'lsa InvalidArgumentException tashlanadi.
     */
    public function updateItem(Accessory $accessory, array $data): Accessory
    {
        return DB::transaction(function () use ($accessory, $data) {
            // Validatsiya: quantity'ni sold+consigned dan kam qilib bo'lmaydi
            if (isset($data['quantity'])) {
                $min = $accessory->sold_quantity + $accessory->consigned_quantity;
                if ((int) $data['quantity'] < $min) {
                    throw new \InvalidArgumentException(
                        "Количество не может быть меньше уже проданного/переданного: {$min} шт."
                    );
                }
            }

            $oldContribution = (float) $accessory->purchase_price * (int) $accessory->quantity;

            $accessory->update($data);
            $accessory->refresh();

            $newContribution = (float) $accessory->purchase_price * (int) $accessory->quantity;
            $delta = $newContribution - $oldContribution;

            if ($accessory->investor_id && abs($delta) > 0.001) {
                $this->cascadeAccessoryPurchaseDelta($accessory, $delta);
            }

            return $accessory;
        });
    }

    /**
     * Accessory uchun purchase delta'ni Transaction/Investment/investor.balance'ga surib qo'yadi.
     * Bulk partiya (accessories bulk) — bitta Transaction ichida bir nechta accessory bo'lishi
     * mumkin (details->accessory_ids), oddiy partiya — bitta (details->accessory_id).
     */
    private function cascadeAccessoryPurchaseDelta(Accessory $accessory, float $delta): void
    {
        $transaction = Transaction::where('type', TransactionType::Purchase)
            ->where('investor_id', $accessory->investor_id)
            ->where(function ($q) use ($accessory) {
                $q->whereJsonContains('details->accessory_ids', $accessory->id)
                  ->orWhere('details->accessory_id', $accessory->id);
            })
            ->first();

        if (!$transaction) {
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

        $investor = Investor::lockForUpdate()->find($accessory->investor_id);
        if ($investor) {
            $investor->balance = (float) $investor->balance - $delta;
            $investor->save();
        }
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
