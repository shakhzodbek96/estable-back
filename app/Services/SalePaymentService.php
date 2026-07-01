<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\ExpenseStatus;
use App\Enums\InvestmentType;
use App\Enums\InventoryStatus;
use App\Enums\SalePaymentStatus;
use App\Enums\TransactionType;
use App\Models\KassaExpense;
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
        // Allaqachon tasdiqlangan bo'lsa (masalan sotuvning boshqa to'lovi orqali) — qayta ishlamaymiz
        if ($payment->status === SalePaymentStatus::Accepted) {
            return $payment->fresh(['sale', 'transaction', 'creator:id,name', 'checker:id,name']);
        }

        if ($payment->status !== SalePaymentStatus::New) {
            throw new \Exception("Faqat yangi to'lovni tasdiqlash mumkin");
        }

        // Tasdiqlash birligi — SOTUV (atomar): bitta to'lovni tasdiqlash butun sotuvni tasdiqlaydi
        $this->acceptSale($payment->sale);

        return $payment->fresh(['sale', 'transaction', 'creator:id,name', 'checker:id,name']);
    }

    /**
     * Sotuvni ATOMAR tasdiqlash — barcha tasdiqlanmagan to'lovlari bitta tranzaksiyada
     * tasdiqlanadi (hammasi yoki hech narsa). Bittasi xato bersa — hammasi rollback bo'ladi,
     * yarim-tasdiqlangan holat YO'Q. Invariant (to'lovlar yig'indisi = sotuv summasi) bir marta tekshiriladi.
     *
     * @return array{accepted:int, payments:array<int,SalePayment>}
     */
    public function acceptSale(Sale $sale): array
    {
        return DB::transaction(function () use ($sale) {
            // Tasdiqlanadigan (yangi) to'lovlarni qulflab olamiz
            $newPayments = $sale->payments()
                ->where('status', SalePaymentStatus::New->value)
                ->lockForUpdate()
                ->get();

            if ($newPayments->isEmpty()) {
                return ['accepted' => 0, 'payments' => []];
            }

            // Invariant: sotuvning rad etilmagan to'lovlari yig'indisi (USD) sotuv summasiga teng bo'lishi SHART.
            $toUsd = fn (SalePayment $p): float => $p->currency === Currency::Usd
                ? (float) $p->amount
                : ((float) $p->rate > 0 ? (float) $p->amount / (float) $p->rate : 0.0);

            $nonRejectedUsd = (float) $sale->payments()
                ->whereNotIn('status', [SalePaymentStatus::Rejected->value, SalePaymentStatus::Cancelled->value])
                ->get()
                ->sum($toUsd);

            if (abs($nonRejectedUsd - (float) $sale->total_price) > 0.01) {
                throw new \Exception(sprintf(
                    'Сумма платежей ($%s) не совпадает с суммой продажи ($%s). Разница: $%s. Исправьте платежи перед подтверждением.',
                    number_format($nonRejectedUsd, 2, '.', ''),
                    number_format((float) $sale->total_price, 2, '.', ''),
                    number_format(abs($nonRejectedUsd - (float) $sale->total_price), 2, '.', '')
                ));
            }

            $accepted = [];
            foreach ($newPayments as $payment) {
                $accepted[] = $this->acceptPaymentRecord($payment);
            }

            return ['accepted' => count($accepted), 'payments' => $accepted];
        });
    }

    /**
     * Bitta to'lov yozuvini tasdiqlash — Transaction + (investor bo'lsa) Investment + balans.
     * Invariant TEKSHIRMAYDI va o'z tranzaksiyasini OCHMAYDI — har doim acceptSale() ichidan chaqiriladi.
     */
    private function acceptPaymentRecord(SalePayment $payment): SalePayment
    {
        if ($payment->currency !== Currency::Usd && (float) $payment->rate <= 0) {
            throw new \Exception("Курс валюты не задан — невозможно пересчитать платёж в USD");
        }
        $amountUsd = $payment->currency === Currency::Usd
            ? (float) $payment->amount
            : (float) $payment->amount / (float) $payment->rate;

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

        $payment->update([
            'status' => SalePaymentStatus::Accepted,
            'transaction_id' => $transaction->id,
            'checked_at' => now(),
            'checked_by' => auth()->id(),
        ]);

        // Investor mablag'ini item darajasida taqsimlaymiz: sotuvda bir necha investor
        // (yoki investor+do'kon) tovari bo'lishi mumkin. Har investor o'z tovarlari SUBTOTAL
        // ulushiga proportsional kreditlanadi. Do'kon (investor_id=null) tovari ulushi
        // kreditlanmaydi. Yagona-investor holatida ulush = 100% → to'liq summa (regressiyasiz).
        $shares = $this->investorSharesUsd($payment->sale);
        $saleTotal = (float) $payment->sale->total_price;

        if (!empty($shares) && $saleTotal > 0) {
            foreach ($shares as $investorId => $subtotalUsd) {
                $credit = round($amountUsd * ($subtotalUsd / $saleTotal), 2);
                if ($credit <= 0) {
                    continue;
                }

                Investment::create([
                    'investor_id' => $investorId,
                    'transaction_id' => $transaction->id,
                    'type' => InvestmentType::ClientsPayment,
                    'is_credit' => true,
                    'amount' => $credit,
                    'rate' => $payment->rate,
                    'comment' => "Sotuv #{$payment->sale_id} to'lovi",
                    'created_by' => auth()->id(),
                ]);

                Investor::where('id', $investorId)
                    ->lockForUpdate()
                    ->increment('balance', $credit);
            }
        }

        return $payment->fresh(['sale', 'transaction', 'creator:id,name', 'checker:id,name']);
    }

    /**
     * Sotuvdagi har investor uchun uning tovarlari SUBTOTAL yig'indisi (USD).
     * Do'kon egaligidagi (investor_id=null) tovarlar kiritilmaydi.
     *
     * @return array<int, float>  [investor_id => subtotal_usd]
     */
    private function investorSharesUsd(Sale $sale): array
    {
        $items = $sale->items()
            ->with(['inventory:id,investor_id', 'accessory:id,investor_id'])
            ->get();

        $shares = [];
        foreach ($items as $item) {
            $investorId = $item->item_type->value === 'serial'
                ? $item->inventory?->investor_id
                : $item->accessory?->investor_id;

            if ($investorId === null) {
                continue; // do'kon mablag'i — kreditlanmaydi
            }

            $shares[$investorId] = ($shares[$investorId] ?? 0.0) + (float) $item->subtotal;
        }

        return $shares;
    }

    /**
     * To'lovni rad etish — sotuv ATOMAR: bitta to'lov rad etilsa butun sotuv bekor bo'ladi
     * (tovarlar skladga qaytadi, qolgan to'lovlar bekor qilinadi). Transaction YARATILMAYDI.
     *
     * Agar sotuvda allaqachon TASDIQLANGAN to'lov bo'lsa — bu yerda bekor qilib bo'lmaydi
     * (pul/investor balansi hisobga olingan): qaytarish (возврат товара) orqali rasmiylashtiriladi.
     */
    public function reject(SalePayment $payment, string $reason): SalePayment
    {
        if ($payment->status !== SalePaymentStatus::New) {
            throw new \Exception("Faqat yangi to'lovni rad etish mumkin");
        }

        $sale = $payment->sale;

        $hasAccepted = $sale->payments()
            ->where('status', SalePaymentStatus::Accepted)
            ->exists();

        if ($hasAccepted) {
            throw new \Exception('В продаже есть подтверждённый платёж. Отмените её через возврат товара.');
        }

        return DB::transaction(function () use ($payment, $reason, $sale) {
            // Joriy to'lovni Rejected qilamiz
            $payment->update([
                'status' => SalePaymentStatus::Rejected,
                'checked_at' => now(),
                'checked_by' => auth()->id(),
                'comment' => $reason,
            ]);

            // Qolgan barcha (New) to'lovlarni Cancelled qilamiz — sotuv atomar bekor bo'ladi
            $sale->payments()
                ->where('id', '!=', $payment->id)
                ->where('status', SalePaymentStatus::New->value)
                ->update([
                    'status' => SalePaymentStatus::Cancelled->value,
                    'checked_at' => now(),
                    'checked_by' => auth()->id(),
                ]);

            // Tovarlarni skladga qaytarish (+ konsignatsiya partner-balans teskari hisobi)
            $this->cancelSale($sale);

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

            // Konsignatsiya tovari bo'lsa — sotuvdagi partner-balans effektini teskari hisoblaymiz
            app(ConsignmentService::class)->handleIncomingItemReturned($item);
        }
    }

    /**
     * Bir nechta to'lovni bir vaqtda tasdiqlash
     */
    public function bulkAccept(array $paymentIds): array
    {
        $results = ['accepted' => 0, 'errors' => []];

        // Tasdiqlash birligi — SOTUV. Tanlangan to'lovlarni sotuv bo'yicha guruhlab,
        // har sotuvni BIR MARTA atomar tasdiqlaymiz (bir to'lovni tanlash butun sotuvni tasdiqlaydi).
        $saleIds = SalePayment::whereIn('id', $paymentIds)
            ->where('status', SalePaymentStatus::New->value)
            ->distinct()
            ->pluck('sale_id');

        foreach ($saleIds as $saleId) {
            try {
                $sale = Sale::findOrFail($saleId);
                $r = $this->acceptSale($sale);
                $results['accepted'] += $r['accepted'];
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'sale_id' => $saleId,
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

    /**
     * Kassa umumiy hisoboti — to'lov usuli × valyuta × status bo'yicha.
     * Filtrlar: seller_id, shop_id, date_from, date_to.
     * Qaytaradi: ['pending' => [...], 'accepted' => [...]] — har method'da {usd, uzs}.
     * Bitta grouped query — pagination cheklovisiz to'liq summalar.
     */
    public function getKassaSummary(array $filters = []): array
    {
        $rows = SalePayment::query()
            ->when($filters['seller_id'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
            ->when($filters['shop_id'] ?? null, fn ($q, $v) => $q->where('shop_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->whereIn('status', [SalePaymentStatus::New, SalePaymentStatus::Accepted])
            ->selectRaw('type, status, currency, SUM(amount) as total')
            ->groupBy('type', 'status', 'currency')
            ->get();

        $blank = fn () => [
            'cash' => ['usd' => 0.0, 'uzs' => 0.0],
            'card' => ['usd' => 0.0, 'uzs' => 0.0],
            'p2p'  => ['usd' => 0.0, 'uzs' => 0.0],
        ];
        $result = ['pending' => $blank(), 'accepted' => $blank(), 'out' => $blank()];

        $val = fn ($x) => $x instanceof \BackedEnum ? $x->value : (string) $x;

        foreach ($rows as $row) {
            $status = $val($row->status);
            $bucket = $status === SalePaymentStatus::New->value ? 'pending'
                : ($status === SalePaymentStatus::Accepted->value ? 'accepted' : null);
            $type = $val($row->type);
            $cur = $val($row->currency);
            if ($bucket === null || !isset($result[$bucket][$type][$cur])) {
                continue; // noma'lum method/valyuta — e'tiborsiz
            }
            $result[$bucket][$type][$cur] = round((float) $row->total, 2);
        }

        // Chiqim (расход/изъятие) — tasdiqlanmagan (pending) kassa_expenses, usul × valyuta.
        // Faqat pending — tasdiqlanгach «к сдаче»dan tushadi (sotuv bilan bir xil pattern).
        $outRows = KassaExpense::query()
            ->where('status', ExpenseStatus::New)
            ->when($filters['shop_id'] ?? null, fn ($q, $v) => $q->where('shop_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->selectRaw('method, currency, SUM(amount) as total')
            ->groupBy('method', 'currency')
            ->get();

        foreach ($outRows as $row) {
            $method = (string) $row->method;
            $cur = $val($row->currency);
            if (!isset($result['out'][$method][$cur])) {
                continue;
            }
            $result['out'][$method][$cur] = round((float) $row->total, 2);
        }

        return $result;
    }
}
