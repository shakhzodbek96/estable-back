<?php

namespace App\Http\Requests\Admin;

use App\Enums\AttributeScope;
use App\Enums\AttributeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreAttributeDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $appliesTo = $this->input('applies_to');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('attribute_definitions', 'name')->where('applies_to', $appliesTo),
            ],
            'icon' => ['nullable', 'string', 'max:50'],
            'icon_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'show_on_label' => ['sometimes', 'boolean'],
            'type' => ['required', new Enum(AttributeType::class)],
            'applies_to' => ['required', new Enum(AttributeScope::class)],
            'options' => ['nullable', 'array', 'required_if:type,select'],
            'options.*' => ['required', 'string', 'max:255', 'distinct'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Характеристика «:input» уже существует в этом списке.',
            'options.required_if' => 'Для типа «Список» нужно указать варианты.',
        ];
    }
}
