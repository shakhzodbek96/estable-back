<?php

namespace App\Enums;

enum ReturnStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
