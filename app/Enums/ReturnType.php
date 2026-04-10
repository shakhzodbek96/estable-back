<?php

namespace App\Enums;

enum ReturnType: string
{
    case Refund = 'refund';
    case ExchangeSame = 'exchange_same';
    case ExchangeDifferent = 'exchange_different';
}
