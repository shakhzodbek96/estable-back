<?php

namespace App\Services;

use App\Enums\ConsignmentDirection;
use App\Enums\ConsignmentStatus;
use App\Enums\InvestmentType;
use App\Enums\InventoryStatus;
use App\Enums\TransactionType;
use App\Models\Accessory;
use App\Models\Consignment;
use App\Models\ConsignmentItem;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Partner;
use App\Models\Rate;
use App\Models\SaleItem;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ConsignmentService
{
    /**
     * OUTGOING — biz partnerga tovar beramiz
     */
    public function createOutgoing(array $data): Consignment
    {
        return DB::transaction(function () use ($data) {
            $consignment = Consignment::create([
                'partner_id' => $data['partner_id'],
                'direction' => ConsignmentDirection::Outgoing,
                'start_date' => now(),
                'deadline' => $data['deadline'] ?? null,
                'status' => ConsignmentStatus::Active,
                'notes' => $data['notes'] ?? null,
                'shop_id' => auth()->user()->shop_id,
                'created_by' => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                $this->addOutgoingItem($consignment, $item);
            }

            return $consignment->load('items');
        });
    }

    private function addOutgoingItem(Consignment $consignment, array $item): ConsignmentItem
    {
        $consignmentItem = ConsignmentItem::create([
            'consignment_id' => $consignment->id,
            'item_type' => $item['item_type'],
            'inventory_id' => $item['inventory_id'] ?? null,
            'accessory_id' => $item['accessory_id'] ?? null,
            'quantity' => $item['quantity'] ?? 1,
            'sold_quantity' => 0,
            'returned_quantity' => 0,
            'agreed_price' => $item['agreed_price'],
            'notes' => $item['notes'] ?? null,
        ]);

        if ($item['item_type'] === 'serial') {
            $inventory = Inventory::findOrFail($item['inventory_id']);
            if ($inventory->status !== InventoryStatus::InStock) {
                throw new \Exception("Tovar mavjud emas: {$inventory->serial_number}");
            }
            $inventory->update(['status' => InventoryStatus::AtPartner]);
        } else {
            $accessory = Accessory::lockForUpdate()->findOrFail($item['accessory_id']);
            $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
            $qty = $item['quantity'] ?? 1;
            if ($available < $qty) {
                throw new \Exception("Yetarli emas. Mavjud: {$available}");
            }
            $accessory->increment('consigned_quantity', $qty);
        }

        return $consignmentItem;
    }

    /**
     * INCOMING — biz partnerdan tovar olamiz
     */
    public function createIncoming(array $data): Consignment
    {
        return DB::transaction(function () use ($data) {
            $consignment = Consignment::create([
                'partner_id' => $data['partner_id'],
                'direction' => ConsignmentDirection::Incoming,
                'start_date' => now(),
                'deadline' => $data['deadline'] ?? null,
                'status' => ConsignmentStatus::Active,
                'notes' => $data['notes'] ?? null,
                'shop_id' => auth()->user()->shop_id,
                'created_by' => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                $this->addIncomingItem($consignment, $item);
            }

            return $consignment->load('items');
        });
    }

    private function addIncomingItem(Consignment $consignment, array $item): ConsignmentItem
    {
        $consignmentItem = ConsignmentItem::create([
            'consignment_id' => $consignment->id,
            'item_type' => $item['item_type'],
            'quantity' => $item['quantity'] ?? 1,
            'sold_quantity' => 0,
            'returned_quantity' => 0,
            'agreed_price' => $item['agreed_price'],
            'notes' => $item['notes'] ?? null,
        ]);

        if ($item['item_type'] === 'serial') {
            $inventory = Inventory::create([
                'product_id' => $item['product_id'],
                'serial_number' => $item['serial_number'],
                'extra_serial_number' => $item['extra_serial_number'] ?? null,
                'purchase_price' => $item['agreed_price'],
                'extra_cost' => 0,
                'selling_price' => $item['selling_price'],
                'status' => InventoryStatus::InStock,
                'has_box' => $item['has_box'] ?? true,
                'state' => $item['state'] ?? 'new',
                'consignment_item_id' => $consignmentItem->id,
                'shop_id' => $consignment->shop_id,
                'investor_id' => null,
                'created_by' => auth()->id(),
            ]);
            $consignmentItem->update(['inventory_id' => $inventory->id]);
        } else {
            $accessory = Accessory::create([
                'product_id' => $item['product_id'],
                'invoice_number' => $item['invoice_number'] ?? "CONS-{$consignment->id}",
                'barcode' => $item['barcode'],
                'quantity' => $item['quantity'],
                'sold_quantity' => 0,
                'consigned_quantity' => 0,
                'purchase_price' => $item['agreed_price'],
                'sell_price' => $item['sell_price'],
                'consignment_item_id' => $consignmentItem->id,
                'shop_id' => $consignment->shop_id,
                'investor_id' => null,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
            $consignmentItem->update(['accessory_id' => $accessory->id]);
        }

        return $consignmentItem;
    }

    /**
     * Outgoing — partner sotganini qayd qilish
     */
    public function reportOutgoingSale(ConsignmentItem $item, int $quantity = 1): void
    {
        DB::transaction(function () use ($item, $quantity) {
            if ($item->sold_quantity + $item->returned_quantity + $quantity > $item->quantity) {
                throw new \Exception("Ko'p sotilgan deb belgilanyapti");
            }

            $item->increment('sold_quantity', $quantity);
            $item->update(['sold_at' => now()]);

            $consignment = $item->consignment;
            $totalAmount = (float) $item->agreed_price * $quantity;
            $rate = Rate::current();

            if ($item->item_type->value === 'serial') {
                $inventory = $item->inventory;
                $inventory->update([
                    'status' => InventoryStatus::Sold,
                    'sold_price' => $item->agreed_price,
                    'sold_at' => now(),
                ]);

                if ($inventory->investor_id) {
                    Investor::where('id', $inventory->investor_id)
                        ->lockForUpdate()
                        ->increment('balance', $totalAmount);

                    Investment::create([
                        'investor_id' => $inventory->investor_id,
                        'type' => InvestmentType::ClientsPayment,
                        'is_credit' => true,
                        'amount' => $totalAmount,
                        'rate' => $rate?->rate ?? 0,
                        'comment' => "Konsignatsiya sotuvi #{$consignment->id}",
                        'created_by' => auth()->id(),
                    ]);
                }
            } else {
                $accessory = $item->accessory;
                $accessory->increment('sold_quantity', $quantity);
                $accessory->decrement('consigned_quantity', $quantity);

                if ($accessory->investor_id) {
                    Investor::where('id', $accessory->investor_id)
                        ->lockForUpdate()
                        ->increment('balance', $totalAmount);

                    Investment::create([
                        'investor_id' => $accessory->investor_id,
                        'type' => InvestmentType::ClientsPayment,
                        'is_credit' => true,
                        'amount' => $totalAmount,
                        'rate' => $rate?->rate ?? 0,
                        'comment' => "Konsignatsiya sotuvi #{$consignment->id}",
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            Transaction::create([
                'amount' => $totalAmount,
                'currency' => 'usd',
                'rate' => $rate?->rate ?? 0,
                'is_credit' => true,
                'type' => TransactionType::ConsignmentReceipt,
                'transaction_date' => now()->toDateString(),
                'shop_id' => $consignment->shop_id,
                'investor_id' => $item->item_type->value === 'serial'
                    ? $item->inventory->investor_id
                    : $item->accessory->investor_id,
                'created_by' => auth()->id(),
                'accepted_by' => auth()->id(),
                'details' => [
                    'consignment_id' => $consignment->id,
                    'consignment_item_id' => $item->id,
                ],
            ]);

            // Partner balansi: musbat (partner bizga qarzdor)
            $consignment->partner->increment('balance', $totalAmount);

            $this->updateConsignmentStatus($consignment);
        });
    }

    /**
     * Incoming tovar sotilganda — SaleService dan chaqiriladi
     */
    public function handleIncomingItemSold(SaleItem $saleItem): void
    {
        $consignmentItemId = null;

        if ($saleItem->item_type->value === 'serial' && $saleItem->inventory?->consignment_item_id) {
            $consignmentItemId = $saleItem->inventory->consignment_item_id;
        } elseif ($saleItem->item_type->value === 'bulk' && $saleItem->accessory?->consignment_item_id) {
            $consignmentItemId = $saleItem->accessory->consignment_item_id;
        }

        if (!$consignmentItemId) return;

        $consignmentItem = ConsignmentItem::with('consignment.partner')->find($consignmentItemId);
        if (!$consignmentItem) return;

        $qty = $saleItem->item_type->value === 'serial' ? 1 : $saleItem->quantity;
        $consignmentItem->increment('sold_quantity', $qty);
        $consignmentItem->update([
            'sale_id' => $saleItem->sale_id,
            'sold_at' => now(),
        ]);

        // Partner balansi: manfiy (biz qarzdormiz)
        $totalDebt = (float) $consignmentItem->agreed_price * $qty;
        $consignmentItem->consignment->partner->decrement('balance', $totalDebt);

        $this->updateConsignmentStatus($consignmentItem->consignment);
    }

    /**
     * Partnerga to'lov qilish
     */
    public function payToPartner(Partner $partner, float $amount, array $data): Transaction
    {
        return DB::transaction(function () use ($partner, $amount, $data) {
            $rate = Rate::current();

            $transaction = Transaction::create([
                'amount' => $amount,
                'currency' => $data['currency'] ?? 'usd',
                'rate' => $data['rate'] ?? $rate?->rate ?? 0,
                'is_credit' => false,
                'type' => TransactionType::ConsignmentPayment,
                'transaction_date' => now()->toDateString(),
                'shop_id' => auth()->user()->shop_id,
                'investor_id' => null,
                'created_by' => auth()->id(),
                'accepted_by' => auth()->id(),
                'details' => [
                    'partner_id' => $partner->id,
                    'note' => $data['note'] ?? null,
                ],
            ]);

            $partner->increment('balance', $amount);

            return $transaction;
        });
    }

    /**
     * Tovarlarni qaytarish
     */
    public function returnItems(Consignment $consignment, array $items): Consignment
    {
        return DB::transaction(function () use ($consignment, $items) {
            foreach ($items as $itemData) {
                $consignmentItem = ConsignmentItem::with(['inventory', 'accessory'])->findOrFail($itemData['consignment_item_id']);
                $quantity = $itemData['quantity'] ?? 1;

                if ($consignmentItem->sold_quantity + $consignmentItem->returned_quantity + $quantity > $consignmentItem->quantity) {
                    throw new \Exception("Ko'p qaytarilyapti");
                }

                $consignmentItem->increment('returned_quantity', $quantity);
                $consignmentItem->update(['returned_at' => now()]);

                if ($consignment->direction === ConsignmentDirection::Outgoing) {
                    if ($consignmentItem->item_type->value === 'serial' && $consignmentItem->inventory) {
                        $consignmentItem->inventory->update(['status' => InventoryStatus::InStock]);
                    } elseif ($consignmentItem->accessory) {
                        $consignmentItem->accessory->decrement('consigned_quantity', $quantity);
                    }
                } else {
                    if ($consignmentItem->item_type->value === 'serial' && $consignmentItem->inventory) {
                        $consignmentItem->inventory->update(['status' => InventoryStatus::ReturnedToPartner]);
                    } elseif ($consignmentItem->accessory) {
                        $consignmentItem->accessory->decrement('quantity', $quantity);
                        // fresh() o'rniga hisoblab tekshirish
                        $newQty = $consignmentItem->accessory->quantity - $quantity;
                        if ($newQty <= 0) {
                            $consignmentItem->accessory->update(['is_active' => false]);
                        }
                    }
                }
            }

            $this->updateConsignmentStatus($consignment);
            return $consignment->fresh()->load('items');
        });
    }

    /**
     * Konsignatsiyani bekor qilish
     */
    public function cancel(Consignment $consignment): Consignment
    {
        if ($consignment->status === ConsignmentStatus::Completed) {
            throw new \Exception("Tugallangan konsignatsiyani bekor qilib bo'lmaydi");
        }

        $pendingItems = $consignment->items()->get()->filter(function ($item) {
            return $item->quantity - $item->sold_quantity - $item->returned_quantity > 0;
        });

        if ($pendingItems->isNotEmpty()) {
            $returnData = $pendingItems->map(fn($item) => [
                'consignment_item_id' => $item->id,
                'quantity' => $item->quantity - $item->sold_quantity - $item->returned_quantity,
            ])->toArray();

            $this->returnItems($consignment, $returnData);
        }

        $consignment->update(['status' => ConsignmentStatus::Cancelled]);
        return $consignment->fresh()->load('items');
    }

    /**
     * Status yangilash
     */
    private function updateConsignmentStatus(Consignment $consignment): void
    {
        $items = $consignment->items()->get();

        $allHandled = $items->every(fn($item) => $item->sold_quantity + $item->returned_quantity == $item->quantity);
        $anyHandled = $items->some(fn($item) => $item->sold_quantity > 0 || $item->returned_quantity > 0);

        if ($allHandled) {
            $consignment->update(['status' => ConsignmentStatus::Completed]);
        } elseif ($anyHandled) {
            $consignment->update(['status' => ConsignmentStatus::PartialReturned]);
        }
    }
}
