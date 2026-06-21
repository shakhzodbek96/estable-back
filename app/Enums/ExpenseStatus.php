<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
