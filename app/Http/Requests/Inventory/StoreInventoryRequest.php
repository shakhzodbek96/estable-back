<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InventoryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Uniqueness faqat skladda turgan tovarlarga: sotilgan serial qayta kiritilishi mumkin
            'serial_number' => ['required', 'string', 'max:255', Rule::unique('inventories', 'serial_number')->where('status', InventoryStatus::InStock->value)],
            'extra_serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'has_box' => ['sometimes', 'boolean'],
            'state' => ['sometimes', 'string', 'in:new,used'],
            'notes' => ['nullable', 'string'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
            'custom_attributes' => ['nullable', 'array'],
            'custom_attributes.*.id' => ['required', 'integer', 'exists:attribute_definitions,id'],
            'custom_attributes.*.value' => ['nullable'],
        ];
    }
}
