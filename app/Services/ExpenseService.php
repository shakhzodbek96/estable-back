<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\ExpenseStatus;
use App\Enums\TransactionType;
use App\Models\CashShift;
use App\Models\KassaExpense;
use App\Models\Rate;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    /** Kassa chiqimida ruxsat etilgan kategoriyalar (TransactionType) */
    public const ALLOWED_TYPES = [
        TransactionType::Salary,     // Зарплата
        TransactionType::Rent,       // Аренда
        TransactionType::Purchase,   // Закупка
        TransactionType::Expense,    // Прочее
        TransactionType::Withdrawal, // Изъятие
    ];

    /** Kassa chiqim usullari */
    public const ALLOWED_METHODS = ['cash', 'card', 'p2p'];

    /**
     * Yangi chiqim — `kassa_expenses` ga `pending` (new) bo'lib yoziladi.
     * Hali ledger'ga (transactions) TUSHMAYDI — faqat admin tasdiqlaganda.
     * Uniform pattern: kim yaratса ham (menejer yoki admin) tasdiqdan o'tadi.
     */
    public function create(array $data): KassaExpense
    {
        // Smena majburiy — ochiq smena bo'lmasa rasxod qilib bo'lmaydi
        $shift = CashShift::openForShop($data['shop_id']);
        if (! $shift) {
            throw new \Exception('Откройте смену перед добавлением расхода');
        }

        $currency = ($data['currency'] ?? 'usd') === 'uzs' ? Currency::Uzs : Currency::Usd;
        $rate = $data['rate'] ?? Rate::current()?->rate ?? 0;

        return KassaExpense::create([
            'shop_id' => $data['shop_id'],
            'shift_id' => $shift->id,
            'type' => $data['type'] instanceof TransactionType ? $data['type']->value : $data['type'],
            'method' => $data['method'],
            'currency' => $currency,
            'amount' => round((float) $data['amount'], 2),
            'rate' => round((float) $rate, 2),
            'status' => ExpenseStatus::New,
            'comment' => $data['comment'] ?? null,
            'details' => $data['details'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Chiqimni tasdiqlash — endi ledger'ga Transaction (is_credit=false) yoziladi.
     * Ledger conventiyasi: amount USD'da, original summa/valyuta/usul `details` ichida.
     */
    public function accept(KassaExpense $expense): KassaExpense
    {
        if ($expense->status !== ExpenseStatus::New) {
            throw new \Exception('Можно подтвердить только новый расход');
        }

        return DB::transaction(function () use ($expense) {
            $amount = (float) $expense->amount;
            if ($expense->currency === Currency::Uzs && (float) $expense->rate <= 0) {
                throw new \Exception('Курс валюты не задан — невозможно пересчитать расход в USD');
            }
            $amountUsd = $expense->currency === Currency::Usd
                ? $amount
                : $amount / (float) $expense->rate;

            $transaction = Transaction::create([
                'amount' => round($amountUsd, 2),
                'currency' => $expense->currency,
                'rate' => $expense->rate,
                'is_credit' => false,
                'type' => $expense->type, // string -> TransactionType cast
                'transaction_date' => now()->toDateString(),
                'details' => [
                    'original_amount' => $amount,
                    'original_currency' => $expense->currency->value,
                    'payment_type' => $expense->method,
                    'comment' => $expense->comment,
                    'kassa' => true,
                    'kassa_expense_id' => $expense->id,
                    // P2P karta ma'lumotlari (audit) — bo'lsa ledger'ga ham o'tadi
                    'card_last4' => $expense->details['card_last4'] ?? null,
                    'time' => $expense->details['time'] ?? null,
                ],
                'shop_id' => $expense->shop_id,
                'shift_id' => $expense->shift_id,
                'created_by' => $expense->created_by,
                'accepted_by' => auth()->id(),
            ]);

            $expense->update([
                'status' => ExpenseStatus::Accepted,
                'transaction_id' => $transaction->id,
                'checked_by' => auth()->id(),
                'checked_at' => now(),
            ]);

            return $expense->fresh();
        });
    }

    /**
     * Chiqimni rad etish — ledger'ga tushmaydi.
     */
    public function reject(KassaExpense $expense, ?string $reason = null): KassaExpense
    {
        if ($expense->status !== ExpenseStatus::New) {
            throw new \Exception('Можно отклонить только новый расход');
        }

        $expense->update([
            'status' => ExpenseStatus::Rejected,
            'checked_by' => auth()->id(),
            'checked_at' => now(),
            'comment' => $reason ? trim(($expense->comment ? $expense->comment . ' · ' : '') . 'Отклонено: ' . $reason) : $expense->comment,
        ]);

        return $expense->fresh();
    }
}
