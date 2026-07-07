<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkUpdateInventoryPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'supply_batch_id' => ['nullable', 'integer', 'exists:supply_batches,id'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer', 'exists:inventories,id'],
            'selling_price' => ['nullable', 'numeric', 'gt:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'state' => ['nullable', 'in:new,used'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.exists' => 'Товар не найден.',
            'supply_batch_id.exists' => 'Партия не найдена.',
            'ids.*.exists' => 'Один из товаров не найден.',
            'selling_price.numeric' => 'Розничная цена должна быть числом.',
            'selling_price.gt' => 'Розничная цена должна быть больше нуля.',
            'wholesale_price.numeric' => 'Оптовая цена должна быть числом.',
            'wholesale_price.min' => 'Оптовая цена не может быть отрицательной.',
            'state.in' => 'Состояние должно быть "новый" или "б/у".',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (!$this->filled('selling_price') && !$this->filled('wholesale_price')) {
                $validator->errors()->add('selling_price', 'Укажите хотя бы одну цену для обновления (розничную или оптовую).');
            }

            if (!$this->filled('product_id') && !$this->filled('supply_batch_id') && !$this->filled('invoice_number') && !$this->filled('ids')) {
                $validator->errors()->add('product_id', 'Укажите хотя бы один фильтр');
            }
        });
    }
}
