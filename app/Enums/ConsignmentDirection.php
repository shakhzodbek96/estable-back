<?php

namespace App\Enums;

enum ConsignmentDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
}
