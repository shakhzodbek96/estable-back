<?php

namespace App\Enums;

/**
 * Qarz daftari yozuvi turi.
 *
 * credit — menga qarzdor (+): odam mendan oldi / men berdim
 * debit  — men qarzdorman (−): men oldim / odam menga berdi
 *
 * Net balans = Σ(credit) − Σ(debit). Musbat → menga qarzdor, manfiy → men qarzdorman.
 */
enum DebtEntryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
