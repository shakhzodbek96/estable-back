<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\InvestmentType;
use App\Enums\InventoryStatus;
use App\Enums\SalePaymentStatus;
use App\Enums\TransactionType;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class SalePaymentService
{
    /**
     * To'lovni tasdiqlash — Transaction + Investment yaratiladi
     */
    public function accept(SalePayment $payment): SalePayment
    {
        if ($payment->status !== SalePaymentStatus::New) {
            throw new \Exception("Faqat yangi to'lovni tasdiqlash mumkin");
        }

        return DB::transaction(function () use ($payment) {
            // 1. Amount USD da hisoblash
            $amountUsd = $payment->currency === Currency::Usd
                ? (float) $payment->amount
                : (float) $payment->amount / (float) $payment->rate;

            // 2. Transaction yaratish
            $transaction = Transaction::create([
                'amount' => round($amountUsd, 2),
                'currency' => $payment->currency,
                'rate' => $payment->rate,
                'is_credit' => true,
                'type' => TransactionType::Sale,
                'transaction_date' => now()->toDateString(),
                'details' => [
                    'sale_id' => $payment->sale_id,
                    'sale_payment_id' => $payment->id,
                    'original_amount' => (float) $payment->amount,
                    'original_currency' => $payment->currency->value,
                    'payment_type' => $payment->type,
                ],
                'shop_id' => $payment->shop_id,
                'investor_id' => $payment->investor_id,
                'created_by' => $payment->created_by,
                'accepted_by' => auth()->id(),
            ]);

            // 3. SalePayment yangilash
            $payment->update([
                'status' => SalePaymentStatus::Accepted,
                'transaction_id' => $transaction->id,
                'checked_at' => now(),
                'checked_by' => auth()->id(),
            ]);

            // 4. Investor balansi va Investment yozuvi
            if ($payment->investor_id) {
                Investment::create([
                    'investor_id' => $payment->investor_id,
                    'transaction_id' => $transaction->id,
                    'type' => InvestmentType::ClientsPayment,
                    'is_credit' => true,
                    'amount' => round($amountUsd, 2),
                    'rate' => $payment->rate,
                    'comment' => "Sotuv #{$payment->sale_id} to'lovi",
                    'created_by' => auth()->id(),
                ]);

                Investor::where('id', $payment->investor_id)
                    ->lockForUpdate()
                    ->increment('balance', round($amountUsd, 2));
            }

            return $payment->fresh(['sale', 'transaction', 'creator:id,name', 'checker:id,name']);
        });
    }

    /**
     * To'lovni rad etish — Transaction YARATILMAYDI
     */
    public function reject(SalePayment $payment, string $reason): SalePayment
    {
        if ($payment->status !== SalePaymentStatus::New) {
            throw new \Exception("Faqat yangi to'lovni rad etish mumkin");
        }

        return DB::transaction(function () use ($payment, $reason) {
            $payment->update([
                'status' => SalePaymentStatus::Rejected,
                'checked_at' => now(),
                'checked_by' => auth()->id(),
                'comment' => $reason,
            ]);

            // Barcha to'lovlar reject/cancelled bo'lsa — sotuvni bekor qilish
            $sale = $payment->sale;
            $activePayments = $sale->payments()
                ->whereNotIn('status', [
                    SalePaymentStatus::Rejected,
                    SalePaymentStatus::Cancelled,
                ])
                ->count();

            if ($activePayments === 0) {
                $this->cancelSale($sale);
            }

            return $payment->fresh(['sale', 'creator:id,name', 'checker:id,name']);
        });
    }

    /**
     * Sotuvni bekor qilish — tovarlarni qaytarish
     */
    private function cancelSale(Sale $sale): void
    {
        foreach ($sale->items()->with(['inventory', 'accessory'])->get() as $item) {
            if ($item->item_type->value === 'serial' && $item->inventory) {
                $item->inventory->update([
                    'status' => InventoryStatus::InStock,
                    'sold_price' => null,
                    'sold_at' => null,
                ]);
            } elseif ($item->item_type->value === 'bulk' && $item->accessory) {
                $item->accessory->decrement('sold_quantity', $item->quantity);

                $accessory = $item->accessory->fresh();
                $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
                if ($available > 0 && !$accessory->is_active) {
                    $accessory->update(['is_active' => true]);
                }
            }
        }
    }

    /**
     * Bir nechta to'lovni bir vaqtda tasdiqlash
     */
    public function bulkAccept(array $paymentIds): array
    {
        $results = ['accepted' => 0, 'errors' => []];

        // Batch load — N query o'rniga 1 ta
        $payments = SalePayment::whereIn('id', $paymentIds)->get()->keyBy('id');

        foreach ($paymentIds as $id) {
            try {
                $payment = $payments->get($id);
                if (!$payment) throw new \Exception("To'lov #{$id} topilmadi");
                $this->accept($payment);
                $results['accepted']++;
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'id' => $id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Sotuvchi kassa hisoboti
     */
    public function getCashSummary(int $sellerId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // 1 ta query bilan barcha statistikani olish
        $stats = SalePayment::where('created_by', $sellerId)
            ->when($dateFrom, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($dateTo, fn($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->selectRaw("status, currency, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('status', 'currency')
            ->get();

        $get = fn(string $status, string $currency = 'usd') =>
            $stats->where('status', $status)->where('currency', $currency)->first();

        $count = fn(string $status) =>
            (int) $stats->where('status', $status)->sum('count');

        // by_type query — bu alohida, chunki type ham kerak
        $byType = SalePayment::where('created_by', $sellerId)
            ->when($dateFrom, fn($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($dateTo, fn($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->selectRaw("type, status, currency, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('type', 'status', 'currency')
            ->get();

        return [
            'pending_count' => $count(SalePaymentStatus::New->value),
            'pending_sum' => round((float) ($get(SalePaymentStatus::New->value, 'usd')?->total ?? 0), 2),
            'pending_sum_uzs' => round((float) ($get(SalePaymentStatus::New->value, 'uzs')?->total ?? 0), 2),
            'accepted_count' => $count(SalePaymentStatus::Accepted->value),
            'accepted_sum' => round((float) ($get(SalePaymentStatus::Accepted->value, 'usd')?->total ?? 0), 2),
            'accepted_sum_uzs' => round((float) ($get(SalePaymentStatus::Accepted->value, 'uzs')?->total ?? 0), 2),
            'rejected_count' => $count(SalePaymentStatus::Rejected->value),
            'rejected_sum' => round((float) ($get(SalePaymentStatus::Rejected->value, 'usd')?->total ?? 0), 2),
            'by_type' => $byType,
        ];
    }
}
