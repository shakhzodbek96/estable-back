<?php

namespace App\Enums;

enum ItemCondition: string
{
    case Resellable = 'resellable';
    case NeedsRepair = 'needs_repair';
    case DefectiveUnusable = 'defective_unusable';
}
