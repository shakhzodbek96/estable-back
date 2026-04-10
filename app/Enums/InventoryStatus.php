<?php

namespace App\Enums;

enum InventoryStatus: string
{
    case InStock = 'in_stock';
    case Sold = 'sold';
    case Installment = 'installment';
    case Moved = 'moved';
    case AtPartner = 'at_partner';
    case Returned = 'returned';
    case InRepair = 'in_repair';
    case ReturnedToPartner = 'returned_to_partner';
    case WrittenOff = 'written_off';
}
