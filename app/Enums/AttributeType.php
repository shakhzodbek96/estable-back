<?php

namespace App\Enums;

enum AttributeType: string
{
    case Text = 'text';        // Строка
    case Number = 'number';    // Число (+ unit)
    case Date = 'date';        // Дата
    case Boolean = 'boolean';  // Да/Нет
    case Select = 'select';    // Список (options)

    /** Tip uchun options (variantlar) majburiymi? */
    public function requiresOptions(): bool
    {
        return $this === self::Select;
    }
}
