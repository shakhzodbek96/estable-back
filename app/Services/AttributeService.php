<?php

namespace App\Services;

use App\Enums\AttributeScope;
use App\Enums\AttributeType;
use App\Models\AttributeDefinition;

class AttributeService
{
    /**
     * Client'dan kelgan atribut qiymatlarini DB ta'riflari bilan tekshirib,
     * saqlanadigan snapshot massiviga aylantiradi: [{id, name, type, value}].
     *
     * Xavfsizlik: name/type/options client'ga ISHONILMAYDI — DB'dan snapshot olinadi.
     * Mos kelmaydigan (id topilmagan, scope'ga tegishli emas, bo'sh qiymat,
     * select uchun ro'yxatda yo'q qiymat) elementlar tashlab yuboriladi.
     *
     * @param  array<int, array{id?: mixed, value?: mixed}>|null  $items
     * @return array<int, array{id:int, name:string, icon:?string, icon_color:?string, unit:?string, type:string, show_on_label:bool, value:mixed}>|null
     */
    public function snapshot(?array $items, AttributeScope $scope): ?array
    {
        if (empty($items)) {
            return null;
        }

        $ids = collect($items)
            ->pluck('id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if (empty($ids)) {
            return null;
        }

        $definitions = AttributeDefinition::query()
            ->active()
            ->forScope($scope)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $result = [];
        $seen = [];

        foreach ($items as $item) {
            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
            if ($id === null || isset($seen[$id]) || ! $definitions->has($id)) {
                continue;
            }

            /** @var AttributeDefinition $def */
            $def = $definitions->get($id);
            $value = $this->castValue($def, $item['value'] ?? null);

            if ($value === null) {
                continue; // bo'sh / yaroqsiz qiymat — saqlanmaydi
            }

            $seen[$id] = true;
            $result[] = [
                'id' => $def->id,
                'name' => $def->name,
                'icon' => $def->icon,
                'icon_color' => $def->icon_color,
                'unit' => $def->unit,
                'type' => $def->type->value,
                'show_on_label' => (bool) $def->show_on_label,
                'value' => $value,
            ];
        }

        return empty($result) ? null : $result;
    }

    /**
     * Qiymatni atribut tipiga ko'ra tozalab qaytaradi. Yaroqsiz/bo'sh bo'lsa — null.
     */
    private function castValue(AttributeDefinition $def, mixed $raw): mixed
    {
        return match ($def->type) {
            AttributeType::Number => is_numeric($raw) ? (float) $raw : null,

            AttributeType::Boolean => is_null($raw) || $raw === ''
                ? null
                : filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),

            AttributeType::Select => $this->cleanSelect($def, $raw),

            // text, date — matn sifatida saqlanadi
            default => $this->cleanString($raw),
        };
    }

    private function cleanString(mixed $raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private function cleanSelect(AttributeDefinition $def, mixed $raw): ?string
    {
        $value = $this->cleanString($raw);
        if ($value === null) {
            return null;
        }

        $options = $def->options ?? [];

        return in_array($value, $options, true) ? $value : null;
    }
}
