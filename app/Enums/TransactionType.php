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
}
