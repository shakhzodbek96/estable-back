<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $inventoryId = $this->route('inventory')?->id;

        return [
            'serial_number' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('inventories', 'serial_number')->ignore($inventoryId)],
            'extra_serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'extra_cost' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'has_box' => ['sometimes', 'boolean'],
            'state' => ['sometimes', 'string', 'in:new,used'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
