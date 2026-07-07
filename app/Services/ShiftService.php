<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Enums\SalePaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\CashShift;
use App\Models\KassaExpense;
use App\Models\SalePayment;

class ShiftService
{
    public function __construct(
        private SalePaymentService $payments,
        private ExpenseService $expenses
    ) {}

    /**
     * Do'kon uchun smena ochish. Ochiq smena mavjud bo'lsa — xato.
     * Boshlang'ich qoldiq odatda 0 (kunlik tsikl).
     */
    public function open(int $shopId, ?array $openingCash = null): CashShift
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($shopId, $openingCash) {
            // Parallel ikki so'rov bir vaqtda smena ochmasligi uchun mavjud ochiq
            // smenani qulflab tekshiramiz. Qat'iy kafolat — DB darajasidagi partial
            // unique index (cash_shifts_one_open_per_shop).
            $existing = CashShift::where('shop_id', $shopId)
                ->where('status', ShiftStatus::Open)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new \Exception('Для этого магазина уже открыта смена');
            }

            return CashShift::create([
                'shop_id' => $shopId,
                'status' => ShiftStatus::Open,
                'opened_by' => auth()->id(),
                'opened_at' => now(),
                'opening_cash' => $openingCash ?? ['usd' => 0, 'uzs' => 0],
            ]);
        });
    }

    /**
     * Kutilayotgan NAQД qoldiq (valyuta bo'yicha):
     *   boshlang'ich + naqд sotuv (rad etilmagan) − naqд rasxod.
     * Faqat cash — терминал/p2p fizik kassada emas.
     */
    public function expectedCash(CashShift $shift): array
    {
        $val = fn ($x) => $x instanceof \BackedEnum ? $x->value : (string) $x;

        // Naqд sotuv (cash), rad etilmagan/bekor qilinmagan
        $salesIn = SalePayment::where('shift_id', $shift->id)
            ->where('type', 'cash')
            ->whereNotIn('status', [SalePaymentStatus::Rejected, SalePaymentStatus::Cancelled])
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->get();

        // Naqд rasxod (pending kassa_expenses, method=cash)
        $expensesOut = KassaExpense::where('shift_id', $shift->id)
            ->where('status', ExpenseStatus::New)
            ->where('method', 'cash')
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->get();

        // Naqд qaytarishlar (refund) — smenada mijozga naqd qaytarilgan pul.
        // Refund tranzaksiyasi doim USD'da yoziladi (ReturnService::approve).
        $refundsOut = (float) \App\Models\Transaction::where('shift_id', $shift->id)
            ->where('type', \App\Enums\TransactionType::Refund)
            ->where(function ($q) {
                $q->where('details->refund_method', 'cash')
                    ->orWhereNull('details->refund_method');
            })
            ->sum('amount');

        $opening = $shift->opening_cash ?? [];
        $expected = [
            'usd' => (float) ($opening['usd'] ?? 0),
            'uzs' => (float) ($opening['uzs'] ?? 0),
        ];

        foreach ($salesIn as $row) {
            $cur = $val($row->currency);
            if (isset($expected[$cur])) {
                $expected[$cur] += (float) $row->total;
            }
        }
        foreach ($expensesOut as $row) {
            $cur = $val($row->currency);
            if (isset($expected[$cur])) {
                $expected[$cur] -= (float) $row->total;
            }
        }

        $expected['usd'] -= $refundsOut;

        return ['usd' => round($expected['usd'], 2), 'uzs' => round($expected['uzs'], 2)];
    }

    /**
     * Smenani yopish: kutilganni hisoblash, farqni qaydlash, va smenaning
     * tasdiqlanmagan to'lovlarini tasdiqlash (handover = rahbar qabul qiladi).
     */
    public function close(CashShift $shift, array $countedCash, ?string $comment = null): array
    {
        if ($shift->status !== ShiftStatus::Open) {
            throw new \Exception('Смена уже закрыта');
        }

        $expected = $this->expectedCash($shift);
        $counted = [
            'usd' => round((float) ($countedCash['usd'] ?? 0), 2),
            'uzs' => round((float) ($countedCash['uzs'] ?? 0), 2),
        ];
        $discrepancy = [
            'usd' => round($counted['usd'] - $expected['usd'], 2),
            'uzs' => round($counted['uzs'] - $expected['uzs'], 2),
        ];

        // Pending to'lovlarni tasdiqlash. Har biri alohida tranzaksiya (accept ichida) —
        // bittasi xato bo'lsa qolganlari to'xtamaydi.
        $pending = SalePayment::where('shift_id', $shift->id)
            ->where('status', SalePaymentStatus::New)
            ->get();

        $accepted = 0;
        $errors = [];
        foreach ($pending as $p) {
            try {
                $this->payments->accept($p);
                $accepted++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $p->id, 'message' => $e->getMessage()];
            }
        }

        // Pending rasxodlarni ham tasdiqlash (handover = admin hammasini qabul qiladi)
        $pendingExpenses = KassaExpense::where('shift_id', $shift->id)
            ->where('status', ExpenseStatus::New)
            ->get();

        $acceptedExpenses = 0;
        foreach ($pendingExpenses as $ex) {
            try {
                $this->expenses->accept($ex);
                $acceptedExpenses++;
            } catch (\Throwable $e) {
                $errors[] = ['expense_id' => $ex->id, 'message' => $e->getMessage()];
            }
        }

        $shift->update([
            'status' => ShiftStatus::Closed,
            'closed_by' => auth()->id(),
            'closed_at' => now(),
            'counted_cash' => $counted,
            'expected_cash' => $expected,
            'discrepancy' => $discrepancy,
            'comment' => $comment,
        ]);

        return [
            'shift' => $shift->fresh(['opener:id,name', 'closer:id,name', 'shop:id,name']),
            'accepted' => $accepted,
            'accepted_expenses' => $acceptedExpenses,
            'errors' => $errors,
        ];
    }
}
