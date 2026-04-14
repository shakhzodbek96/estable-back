<?php

namespace App\Services;

use App\Enums\InvestmentType;
use App\Enums\InventoryStatus;
use App\Enums\ItemCondition;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use App\Models\Return_;
use App\Models\SaleItem;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    /**
     * Yangi qaytarish yaratish (status=pending)
     */
    public function create(array $data): Return_
    {
        $saleItem = SaleItem::with(['sale', 'inventory', 'accessory'])->findOrFail($data['sale_item_id']);

        // Allaqachon qaytarilganmi?
        $existing = Return_::where('sale_item_id', $saleItem->id)
            ->whereIn('status', [ReturnStatus::Pending, ReturnStatus::Completed])
            ->exists();

        if ($existing) {
            throw new \Exception("Bu tovar allaqachon qaytarilgan yoki kutilmoqda");
        }

        return Return_::create([
            'sale_id' => $saleItem->sale_id,
            'sale_item_id' => $saleItem->id,
            'customer_id' => $saleItem->sale->customer_id,
            'reason' => $data['reason'],
            'reason_note' => $data['reason_note'] ?? null,
            'return_type' => $data['return_type'],
            'refund_amount' => $data['refund_amount'] ?? null,
            'refund_method' => $data['refund_method'] ?? null,
            'price_difference' => $data['price_difference'] ?? null,
            'item_condition' => $data['item_condition'],
            'transfers_to_shop' => $data['transfers_to_shop'] ?? false,
            'status' => ReturnStatus::Pending,
            'shop_id' => auth()->user()->shop_id ?? $saleItem->sale->shop_id,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Qaytarishni tasdiqlash
     */
    public function approve(Return_ $return): Return_
    {
        if ($return->status !== ReturnStatus::Pending) {
            throw new \Exception("Faqat kutilayotgan qaytarishni tasdiqlash mumkin");
        }

        return DB::transaction(function () use ($return) {
            $return->update([
                'status' => ReturnStatus::Completed,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $saleItem = $return->saleItem()->with(['inventory', 'accessory'])->first();
            $investorId = $saleItem->sale->investor_id;

            // Tovar statusini yangilash
            if ($saleItem->item_type->value === 'serial' && $saleItem->inventory) {
                $newStatus = match ($return->item_condition) {
                    ItemCondition::Resellable => InventoryStatus::InStock,
                    ItemCondition::NeedsRepair => InventoryStatus::Returned,
                    ItemCondition::DefectiveUnusable => InventoryStatus::WrittenOff,
                };

                $updateData = [
                    'status' => $newStatus,
                    'sold_at' => null,
                    'sold_price' => null,
                ];

                if ($return->transfers_to_shop) {
                    $updateData['investor_id'] = null;
                }

                $saleItem->inventory->update($updateData);
            } elseif ($saleItem->item_type->value === 'bulk' && $saleItem->accessory) {
                $saleItem->accessory->decrement('sold_quantity', $saleItem->quantity);

                if ($return->item_condition === ItemCondition::DefectiveUnusable) {
                    $saleItem->accessory->decrement('quantity', $saleItem->quantity);
                }

                $accessory = $saleItem->accessory->fresh();
                $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
                if ($available > 0 && !$accessory->is_active) {
                    $accessory->update(['is_active' => true]);
                }
            }

            // Refund uchun Transaction va investor balansi
            if ($return->return_type === ReturnType::Refund && $return->refund_amount > 0) {
                $rate = Rate::current();

                $refundTransaction = Transaction::create([
                    'amount' => $return->refund_amount,
                    'currency' => 'usd',
                    'rate' => $rate?->rate ?? 0,
                    'is_credit' => false,
                    'type' => TransactionType::Refund,
                    'transaction_date' => now()->toDateString(),
                    'shop_id' => $return->shop_id,
                    'investor_id' => $return->transfers_to_shop ? null : $investorId,
                    'created_by' => $return->created_by,
                    'accepted_by' => auth()->id(),
                    'details' => [
                        'return_id' => $return->id,
                        'sale_id' => $return->sale_id,
                    ],
                ]);

                if ($investorId && !$return->transfers_to_shop) {
                    Investor::where('id', $investorId)
                        ->lockForUpdate()
                        ->decrement('balance', $return->refund_amount);

                    Investment::create([
                        'investor_id' => $investorId,
                        'transaction_id' => $refundTransaction->id,
                        'type' => InvestmentType::ClientsPayment,
                        'is_credit' => false,
                        'amount' => $return->refund_amount,
                        'rate' => $rate?->rate ?? 0,
                        'comment' => "Qaytarish #{$return->id}",
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            return $return->fresh([
                'sale', 'saleItem.inventory.product', 'saleItem.accessory.product',
                'customer', 'creator:id,name', 'approver:id,name',
            ]);
        });
    }

    /**
     * Qaytarishni rad etish
     */
    public function reject(Return_ $return, ?string $reason = null): Return_
    {
        if ($return->status !== ReturnStatus::Pending) {
            throw new \Exception("Faqat kutilayotgan qaytarishni rad etish mumkin");
        }

        $return->update([
            'status' => ReturnStatus::Rejected,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'reason_note' => $reason ? ($return->reason_note . "\nRad etish: " . $reason) : $return->reason_note,
        ]);

        return $return->fresh();
    }

    /**
     * Tuzatishga yuborish
     */
    public function sendToRepair(Inventory $inventory): Inventory
    {
        if ($inventory->status !== InventoryStatus::Returned) {
            throw new \Exception("Faqat 'returned' statusdagi tovarni tuzatishga yuborish mumkin");
        }

        $inventory->update(['status' => InventoryStatus::InRepair]);
        return $inventory;
    }

    /**
     * Tuzatishdan qaytarish
     */
    public function returnFromRepair(Inventory $inventory): Inventory
    {
        if ($inventory->status !== InventoryStatus::InRepair) {
            throw new \Exception("Tovar tuzatishda emas");
        }

        $inventory->update(['status' => InventoryStatus::InStock]);
        return $inventory;
    }

    /**
     * Hisobdan chiqarish
     */
    public function writeOff(Inventory $inventory, string $reason): Inventory
    {
        return DB::transaction(function () use ($inventory, $reason) {
            $totalCost = (float) $inventory->purchase_price + (float) $inventory->extra_cost;

            $inventory->update([
                'status' => InventoryStatus::WrittenOff,
                'notes' => ($inventory->notes ?? '') . "\nHisobdan chiqarildi: {$reason}",
            ]);

            $rate = Rate::current();

            Transaction::create([
                'amount' => $totalCost,
                'currency' => 'usd',
                'rate' => $rate?->rate ?? 0,
                'is_credit' => false,
                'type' => TransactionType::WriteOff,
                'transaction_date' => now()->toDateString(),
                'shop_id' => $inventory->shop_id,
                'investor_id' => $inventory->investor_id,
                'created_by' => auth()->id(),
                'accepted_by' => auth()->id(),
                'details' => [
                    'inventory_id' => $inventory->id,
                    'reason' => $reason,
                ],
            ]);

            if ($inventory->investor_id) {
                Investor::where('id', $inventory->investor_id)
                    ->lockForUpdate()
                    ->decrement('balance', $totalCost);
            }

            return $inventory;
        });
    }
}
