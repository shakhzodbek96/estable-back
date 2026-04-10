<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'serial_number' => ['required', 'string', 'max:255', 'unique:inventories,serial_number'],
            'extra_serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'has_box' => ['sometimes', 'boolean'],
            'state' => ['sometimes', 'string', 'in:new,used'],
            'notes' => ['nullable', 'string'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
        ];
    }
}
