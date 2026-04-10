<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case Cash = 'cash';
    case P2p = 'p2p';
    case Multiple = 'multiple';
}
