<?php

namespace App\Models;

use App\Enums\AttributeScope;
use App\Enums\AttributeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dinamik tovar xususiyati ta'rifi (reusable).
 *
 * Tovar qo'shishda foydalanuvchi shu ta'riflardan tanlab qiymat kiritadi.
 * Qiymatlar inventories.attributes / accessories.attributes (jsonb) ga
 * snapshot bilan saqlanadi.
 *
 * $connection belgilanmaydi — tenant context'da avto 'tenant'ga o'tadi.
 */
class AttributeDefinition extends Model
{
    protected $fillable = [
        'name',
        'icon',
        'icon_color',
        'type',
        'options',
        'unit',
        'applies_to',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'applies_to' => AttributeScope::class,
            'options' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Berilgan tovar turi (serial|bulk) uchun mos atributlar (both ham kiradi). */
    public function scopeForScope(Builder $query, AttributeScope $scope): Builder
    {
        return $query->whereIn('applies_to', [$scope->value, AttributeScope::Both->value]);
    }
}
