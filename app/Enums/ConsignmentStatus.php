<?php

namespace App\Enums;

enum ConsignmentStatus: string
{
    case Active = 'active';
    case PartialReturned = 'partial_returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
