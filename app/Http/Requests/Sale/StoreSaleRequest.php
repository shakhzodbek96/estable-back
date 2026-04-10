<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sale_date' => ['nullable', 'date'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'string', 'in:serial,bulk'],
            'items.*.inventory_id' => ['nullable', 'required_if:items.*.item_type,serial', 'integer', 'exists:inventories,id'],
            'items.*.accessory_id' => ['nullable', 'required_if:items.*.item_type,bulk', 'integer', 'exists:accessories,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.warranty_months' => ['nullable', 'integer', 'min:0'],
            'items.*.warranty_note' => ['nullable', 'string', 'max:500'],

            'payments' => ['required', 'array', 'min:1'],
            'payments.*.type' => ['required', 'string', 'in:cash,card,p2p'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.currency' => ['nullable', 'string', 'in:usd,uzs'],
            'payments.*.rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
