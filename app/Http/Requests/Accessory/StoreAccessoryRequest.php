<?php

namespace App\Http\Requests\Accessory;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'barcode' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
        ];
    }
}
