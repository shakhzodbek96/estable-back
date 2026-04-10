<?php

namespace App\Enums;

enum ReturnReason: string
{
    case Defect = 'defect';
    case CustomerChangeMind = 'customer_change_mind';
    case Warranty = 'warranty';
    case Other = 'other';
}
