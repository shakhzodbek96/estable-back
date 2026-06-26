<?php

namespace App\Services;

use App\Enums\Currency;
use App\Models\DebtContact;
use App\Models\DebtEntry;
use Illuminate\Support\Collection;

/**
 * Qarz daftari mantiqi — balanslar valyuta bo'yicha YOZUVLARDAN hisoblanadi
 * (kontaktda saqlanmaydi → drift bo'lmaydi).
 *
 * balans = Σ(credit) − Σ(debit). Musbat → menga qarzdor, manfiy → men qarzdorman.
 */
class DebtService
{
    private const BALANCE_EXPR = "SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END)";

    /**
     * Berilgan kontaktlar uchun valyuta bo'yicha net balanslar.
     *
     * @param  array<int>  $contactIds
     * @return Collection<int, array<int, array{currency:string, balance:float}>>  contact_id bo'yicha keyed
     */
    public function balancesFor(array $contactIds): Collection
    {
        if (empty($contactIds)) {
            return collect();
        }

        return DebtEntry::query()
            ->selectRaw('debt_contact_id, currency, ' . self::BALANCE_EXPR . ' as balance')
            ->whereIn('debt_contact_id', $contactIds)
            ->groupBy('debt_contact_id', 'currency')
            ->get()
            ->groupBy('debt_contact_id')
            ->map(fn ($rows) => $rows
                ->filter(fn ($r) => (float) $r->balance != 0.0)
                ->map(fn ($r) => [
                    'currency' => $this->currencyValue($r->currency),
                    'balance' => (float) $r->balance,
                ])
                ->values()
                ->all());
    }

    /**
     * Butun daftar bo'yicha umumiy ko'rsatkichlar (valyuta bo'yicha):
     * owed_to_me — menga jami qarzdor, i_owe — men jami qarzdorman.
     *
     * @return array<int, array{currency:string, owed_to_me:float, i_owe:float}>
     */
    public function summary(): array
    {
        $rows = DebtEntry::query()
            ->selectRaw('debt_contact_id, currency, ' . self::BALANCE_EXPR . ' as balance')
            ->groupBy('debt_contact_id', 'currency')
            ->get();

        $totals = [];
        foreach ($rows as $r) {
            $cur = $this->currencyValue($r->currency);
            $bal = (float) $r->balance;
            $totals[$cur] ??= ['currency' => $cur, 'owed_to_me' => 0.0, 'i_owe' => 0.0];

            if ($bal > 0) {
                $totals[$cur]['owed_to_me'] += $bal;
            } elseif ($bal < 0) {
                $totals[$cur]['i_owe'] += -$bal;
            }
        }

        return array_values($totals);
    }

    public function addEntry(DebtContact $contact, array $data): DebtEntry
    {
        return $contact->entries()->create([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'comment' => $data['comment'] ?? null,
            'entry_date' => $data['entry_date'],
            'due_date' => $data['due_date'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateEntry(DebtEntry $entry, array $data): DebtEntry
    {
        $entry->update($data);

        return $entry;
    }

    public function deleteEntry(DebtEntry $entry): void
    {
        $entry->delete();
    }

    private function currencyValue(mixed $currency): string
    {
        return $currency instanceof Currency ? $currency->value : (string) $currency;
    }
}
