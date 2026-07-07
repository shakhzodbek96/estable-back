<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\Rate;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Postavshik qarzini (nasiya) qaytarish — погашение долга поставщику.
 * Kassadan chiqim (Transaction, is_credit=false) yaratadi va supplier.balance'ni kamaytiradi.
 */
class SupplierPaymentService
{
    /** Postavshikka to'lov usullari (kassa chiqim bilan bir xil) */
    public const ALLOWED_METHODS = ['cash', 'card', 'p2p'];

    public function pay(Supplier $supplier, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($supplier, $data) {
            /** @var Supplier $locked */
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->first();

            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new \Exception('Сумма оплаты должна быть больше нуля');
            }

            $currency = ($data['currency'] ?? 'usd') === 'uzs' ? Currency::Uzs : Currency::Usd;
            $rate = (float) ($data['rate'] ?? Rate::current()?->rate ?? 0);

            if ($currency === Currency::Uzs && $rate <= 0) {
                throw new \Exception('Курс валюты не задан — невозможно пересчитать сумму в USD');
            }

            // Balance USD da — UZS to'lovni USD ga o'giramiz.
            $amountUsd = $currency === Currency::Usd ? $amount : round($amount / $rate, 2);

            if ($amountUsd > (float) $locked->balance + 0.01) {
                throw new \Exception('Сумма оплаты превышает долг поставщику');
            }

            $transaction = Transaction::create([
                'amount' => $amountUsd,
                'currency' => $currency,
                'rate' => $rate,
                'is_credit' => false,
                'type' => TransactionType::SupplierPayment,
                'transaction_date' => now()->toDateString(),
                'details' => [
                    'supplier_id' => $locked->id,
                    'supplier_name' => $locked->name,
                    'supply_batch_id' => $data['supply_batch_id'] ?? null,
                    'original_amount' => $amount,
                    'original_currency' => $currency->value,
                    'payment_type' => $data['payment_method'],
                    'comment' => $data['comment'] ?? null,
                ],
                'shop_id' => $data['shop_id'],
                'created_by' => auth()->id(),
                'accepted_by' => auth()->id(),
            ]);

            $payment = SupplierPayment::create([
                'supplier_id' => $locked->id,
                'supply_batch_id' => $data['supply_batch_id'] ?? null,
                'amount' => $amount,
                'currency' => $currency->value,
                'rate' => $rate,
                'transaction_id' => $transaction->id,
                'comment' => $data['comment'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $locked->decrement('balance', $amountUsd);

            return $payment->fresh(['transaction', 'creator', 'batch']);
        });
    }
}
