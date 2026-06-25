<?php

namespace App\Enums;

/**
 * Atribut qaysi tovar turiga tegishli:
 *   - Serial — donalik (IMEI) tovarlar (inventories)
 *   - Bulk   — aksessuarlar (accessories)
 *   - Both   — ikkalasida ham ko'rinadi
 */
enum AttributeScope: string
{
    case Serial = 'serial';
    case Bulk = 'bulk';
    case Both = 'both';

    /** Berilgan tovar turi ($appliesTo) ushbu scope'ga mos keladimi? */
    public function matches(self $target): bool
    {
        return $this === self::Both || $this === $target;
    }
}
