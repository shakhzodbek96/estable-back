<?php

namespace App\Enums;

enum TransactionType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case Repair = 'repair';
    case WriteOff = 'write_off';
    case Consumption = 'consumption';
    case Salary = 'salary';
    case Rent = 'rent';
    case Purchase = 'purchase';
    case ConsignmentPayment = 'consignment_payment';
    case ConsignmentReceipt = 'consignment_receipt';
    case InvestorProfitShare = 'investor_profit_share'; // do'kon ulushi (dividend yechilganda investordan olingan foyda)
    case Expense = 'expense';       // Kassa: prochie rasxodlar
    case Withdrawal = 'withdrawal'; // Kassa: izyatie (egaga pul berish)
}
