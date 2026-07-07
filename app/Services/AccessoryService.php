<?php

namespace App\Services;

use App\Enums\AttributeScope;
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
    public function __construct(
        private AttributeService $attributes,
        private SupplyBatchService $batches,
    ) {}

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

            // Партия (ixtiyoriy) — postavshik/manba berilsa yaratiladi.
            $supplyBatch = $this->batches->createForIntake($data, $data['shop_id']);
            $isCredit = $this->batches->isCredit($supplyBatch);

            // Nasiya kirimida investor ishtirok etmaydi (mol qarzga olindi).
            if ($isCredit) {
                $data['investor_id'] = null;
            }

            // Dinamik xususiyatlar — barcha partiyalarga umumiy snapshot
            $customAttributes = $this->attributes->snapshot($data['custom_attributes'] ?? null, AttributeScope::Bulk);

            foreach ($data['batches'] as $batch) {
                $accessory = Accessory::create([
                    // Import — har partiyada o'z product_id; qo'lda bulk — umumiy product_id
                    'product_id' => $batch['product_id'] ?? $data['product_id'],
                    'invoice_number' => $data['invoice_number'],
                    'barcode' => $batch['barcode'],
                    'quantity' => $batch['quantity'],
                    'sold_quantity' => 0,
                    'consigned_quantity' => 0,
                    'purchase_price' => $batch['purchase_price'],
                    'sell_price' => $batch['sell_price'],
                    'wholesale_price' => $batch['wholesale_price'] ?? null,
                    'notes' => $batch['notes'] ?? null,
                    'custom_attributes' => $customAttributes,
                    'supply_batch_id' => $supplyBatch?->id,
                    'shop_id' => $data['shop_id'],
                    'investor_id' => $data['investor_id'] ?? null,
                    'is_active' => true,
                    'created_by' => auth()->id(),
                ]);

                $accessories->push($accessory);
                $totalCost += (float) $batch['purchase_price'] * (int) $batch['quantity'];
            }

            // Partiya total_cost + nasiya qarzi.
            if ($supplyBatch) {
                $this->batches->finalize($supplyBatch, $totalCost);
            }

            // Nasiya — kassadan chiqim YO'Q (keyin postavshikka to'lanadi).
            if (! $isCredit && $totalCost > 0) {
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
                        'accessory_ids' => $accessories->pluck('id')->all(),
                        'invoice_number' => $data['invoice_number'],
                        'batches_count' => $accessories->count(),
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
                        'comment' => "Aksessuar partiyalari: {$accessories->count()} ta (накл. {$data['invoice_number']})",
                        'created_by' => auth()->id(),
                    ]);

                    $investor = Investor::lockForUpdate()->find($investorId);
                    $investor->decrement('balance', $totalCost);
                }
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
                'custom_attributes' => $this->attributes->snapshot($data['custom_attributes'] ?? null, AttributeScope::Bulk),
                'shop_id' => $data['shop_id'],
                'investor_id' => $data['investor_id'] ?? null,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            $totalCost = $data['purchase_price'] * $data['quantity'];

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
                        'accessory_id' => $accessory->id,
                        'barcode' => $data['barcode'],
                        'quantity' => $data['quantity'],
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
                        'comment' => "Aksessuar partiyasi: {$data['barcode']}",
                        'created_by' => auth()->id(),
                    ]);

                    $investor = Investor::lockForUpdate()->find($investorId);
                    $investor->decrement('balance', $totalCost);
                }
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
            // Client'dan kelgan [{id,value}] ni saqlanadigan snapshot'ga aylantiramiz
            if (array_key_exists('custom_attributes', $data)) {
                $data['custom_attributes'] = $this->attributes->snapshot($data['custom_attributes'], AttributeScope::Bulk);
            }

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

    /**
     * Aksessuar partiyasini o'chirish. Investor mablag'iga olingan bo'lsa — xaridni teskari
     * hisoblaymiz: kapital (purchase_price * quantity) investor balansiga qaytadi, bog'liq
     * Purchase Transaction/Investment summasi kamayadi (oxirgisi bo'lsa — o'chiriladi).
     */
    public function deleteItem(Accessory $accessory): void
    {
        DB::transaction(function () use ($accessory) {
            if ($accessory->investor_id) {
                $contribution = (float) $accessory->purchase_price * (int) $accessory->quantity;
                $this->reverseAccessoryPurchase($accessory, $contribution);
            }
            $accessory->delete();
        });
    }

    private function reverseAccessoryPurchase(Accessory $accessory, float $contribution): void
    {
        Investor::where('id', $accessory->investor_id)->lockForUpdate()->increment('balance', $contribution);

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

        $ids = collect($transaction->details['accessory_ids'] ?? [])
            ->reject(fn ($id) => (int) $id === (int) $accessory->id)
            ->values()->all();
        $hadArray = array_key_exists('accessory_ids', $transaction->details ?? []);
        $newAmount = (float) $transaction->amount - $contribution;

        // Oddiy partiya (accessory_id, bitta) yoki bulk'da oxirgi accessory bo'lsa — tranzaksiyani o'chiramiz
        if (! $hadArray || empty($ids) || $newAmount <= 0.001) {
            Investment::where('transaction_id', $transaction->id)->delete();
            $transaction->delete();
            return;
        }

        $details = $transaction->details;
        $details['accessory_ids'] = $ids;
        $transaction->details = $details;
        $transaction->amount = $newAmount;
        $transaction->save();

        Investment::where('transaction_id', $transaction->id)->get()->each(function (Investment $inv) use ($contribution) {
            $inv->amount = (float) $inv->amount - $contribution;
            $inv->save();
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
