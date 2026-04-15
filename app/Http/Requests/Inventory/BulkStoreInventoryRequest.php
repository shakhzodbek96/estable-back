<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'has_box' => ['sometimes', 'boolean'],
            'state' => ['sometimes', 'string', 'in:new,used'],
            'notes' => ['nullable', 'string'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
            'serials' => ['required', 'array', 'min:1'],
            'serials.*.serial_number' => ['required', 'string', 'max:255', 'distinct', 'unique:inventories,serial_number'],
            'serials.*.extra_serial_number' => ['nullable', 'string', 'max:255'],
            'serials.*.extra_cost' => ['nullable', 'numeric', 'min:0'],
            'serials.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'serials.*.serial_number.unique' => 'IMEI :input уже существует в базе.',
            'serials.*.serial_number.distinct' => 'IMEI :input дублируется в списке.',
        ];
    }
}
