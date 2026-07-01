<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\InvestmentType;
use App\Enums\InventoryStatus;
use App\Enums\ItemCondition;
use App\Enums\ReturnStatus;
use App\Enums\ReturnType;
use App\Enums\SalePaymentStatus;
use App\Enums\TransactionType;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use App\Models\Return_;
use App\Models\SaleItem;
use App\Models\SalePayment;
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

        $isSerial = $saleItem->item_type->value === 'serial';
        $lineQty = $isSerial ? 1 : (int) $saleItem->quantity;

        // Shu sotuv qatori bo'yicha allaqachon qaytarilgan (kutilayotgan + tugallangan) miqdor
        $alreadyReturned = (int) Return_::where('sale_item_id', $saleItem->id)
            ->whereIn('status', [ReturnStatus::Pending, ReturnStatus::Completed])
            ->sum('returned_quantity');

        // Qaytarilayotgan miqdor: serial -> doim 1; bulk -> berilgan yoki qolgan butun miqdor
        $qty = $isSerial ? 1 : (int) ($data['returned_quantity'] ?? max(0, $lineQty - $alreadyReturned));

        if ($qty < 1) {
            throw new \Exception("Noto'g'ri qaytarish miqdori");
        }

        if ($alreadyReturned + $qty > $lineQty) {
            $remaining = max(0, $lineQty - $alreadyReturned);
            throw new \Exception("Можно вернуть не более {$remaining} ед. (уже возвращено {$alreadyReturned} из {$lineQty}).");
        }

        return Return_::create([
            'sale_id' => $saleItem->sale_id,
            'sale_item_id' => $saleItem->id,
            'returned_quantity' => $qty,
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

        // Obmen (ExchangeSame/ExchangeDifferent) hozircha hisob-kitobni amalga oshirmaydi:
        // original sotuv krediti teskari hisoblanmaydi, price_difference va yangi sotuv (new_sale_id)
        // yozilmaydi. Jim "yarim ishlash" o'rniga aniq bloklaymiz — to'liq qo'llab-quvvatlash
        // alohida funksional sifatida qo'shilishi kerak (qaytarish + yangi sotuv rasmiylashtirish).
        if (in_array($return->return_type, [ReturnType::ExchangeSame, ReturnType::ExchangeDifferent], true)) {
            throw new \Exception('Обмен товара пока не поддерживается в учёте. Оформите возврат, затем новую продажу.');
        }

        return DB::transaction(function () use ($return) {
            $return->update([
                'status' => ReturnStatus::Completed,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $saleItem = $return->saleItem()->with(['inventory', 'accessory', 'sale'])->first();

            // Egа — item'ning O'ZINIKI (sale->investor_id emas): sotuvda bir necha investor
            // bo'lishi mumkin, mablag' item darajasida taqsimlangan. Qaytarishda ayni shu
            // tovar egasidan (agar investor bo'lsa) teskari hisoblaymiz.
            $investorId = $saleItem->item_type->value === 'serial'
                ? $saleItem->inventory?->investor_id
                : $saleItem->accessory?->investor_id;

            // Qaytarilayotgan miqdor — bulk uchun qisman bo'lishi mumkin; serial uchun doim 1.
            $qty = $saleItem->item_type->value === 'serial'
                ? 1
                : (int) ($return->returned_quantity ?? $saleItem->quantity);

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
                // Faqat qaytarilgan miqdorni qaytaramiz (qisman qaytarishni qo'llab-quvvatlash)
                $saleItem->accessory->decrement('sold_quantity', $qty);

                if ($return->item_condition === ItemCondition::DefectiveUnusable) {
                    $saleItem->accessory->decrement('quantity', $qty);
                }

                $accessory = $saleItem->accessory->fresh();
                $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
                if ($available > 0 && !$accessory->is_active) {
                    $accessory->update(['is_active' => true]);
                }
            }

            // Konsignatsiya (incoming) tovari bo'lsa — sotuvdagi partner-balans va
            // consignmentItem.sold_quantity effektini teskari hisoblaymiz (qty bo'yicha).
            app(ConsignmentService::class)->handleIncomingItemReturned($saleItem, $qty);

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
                    // Investor sotuv to'lovlari qabul qilingani sayin INKREMENTAL kreditlanadi
                    // (SalePaymentService::acceptPaymentRecord), har egа o'z tovarlari ulushiga
                    // proportsional. Shuning uchun qaytarishda faqat AYNI investorga, AYNI
                    // qaytarilayotgan tovar uchun haqiqatda kreditlangan summagacha qaytaramiz.
                    //
                    //   itemCreditedUsd = (qabul qilingan to'lovlar USD) × (qaytarilgan subtotal / sotuv summasi)
                    //
                    // Bu balansning asossiz manfiyga ketishini va boshqa investor ulushiga
                    // tegib ketishini oldini oladi. Yagona-investor to'liq to'langan sotuvda
                    // itemCreditedUsd = qaytarilgan subtotal ≥ refund → eski xatti-harakat saqlanadi.
                    $acceptedUsd = (float) SalePayment::where('sale_id', $return->sale_id)
                        ->where('status', SalePaymentStatus::Accepted)
                        ->get()
                        ->sum(fn ($p) => $p->currency === Currency::Usd
                            ? (float) $p->amount
                            : (float) $p->amount / max((float) $p->rate, 0.0000001));

                    $saleTotal = (float) $saleItem->sale->total_price;
                    $returnedSubtotal = (float) $saleItem->unit_price * $qty; // serial: qty=1
                    $itemCreditedUsd = $saleTotal > 0
                        ? $acceptedUsd * ($returnedSubtotal / $saleTotal)
                        : 0.0;

                    $reverseAmount = min((float) $return->refund_amount, $itemCreditedUsd);

                    if ($reverseAmount > 0) {
                        Investor::where('id', $investorId)
                            ->lockForUpdate()
                            ->decrement('balance', $reverseAmount);

                        Investment::create([
                            'investor_id' => $investorId,
                            'transaction_id' => $refundTransaction->id,
                            'type' => InvestmentType::ClientsPayment,
                            'is_credit' => false,
                            'amount' => $reverseAmount,
                            'rate' => $rate?->rate ?? 0,
                            'comment' => "Qaytarish #{$return->id}",
                            'created_by' => auth()->id(),
                        ]);
                    }
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
     * Hisobdan chiqarish
     */
    public function writeOff(Inventory $inventory, string $reason): Inventory
    {
        if ($inventory->status === InventoryStatus::WrittenOff) {
            throw new \Exception('Товар уже списан');
        }

        return DB::transaction(function () use ($inventory, $reason) {
            $totalCost = (float) $inventory->purchase_price + (float) $inventory->extra_cost;

            $inventory->update([
                'status' => InventoryStatus::WrittenOff,
                'notes' => ($inventory->notes ?? '') . "\nHisobdan chiqarildi: {$reason}",
            ]);

            $rate = Rate::current();

            // WriteOff — faqat yo'qotish/xarajat yozuvi. Investor balansiga TEGILMAYDI:
            // kapital xarid paytida allaqachon balansdan chiqqan (investor inventarga egalik
            // qiladi va riskni o'zi ko'taradi), shuning uchun bu yerda yana decrement qilish
            // ikki marta hisoblash bo'lardi. Zarar = sotilmagan/qaytmagan kapital.
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

            return $inventory;
        });
    }
}
