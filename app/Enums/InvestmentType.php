<?php

namespace App\Enums;

enum InvestmentType: int
{
    case Investment = 1;
    case Dividend = 2;
    case ClientsPayment = 3;
    case BuyingProduct = 4;
}
