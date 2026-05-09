<?php

namespace App\Http\Requests\Accessory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shopId = $this->input('shop_id');

        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'barcode' => [
                'required', 'string', 'max:255',
                Rule::unique('accessories', 'barcode')->where(function ($q) use ($shopId) {
                    $q->where('shop_id', $shopId)->where('is_active', true);
                }),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'barcode.unique' => 'Штрих-код :input уже существует в этом магазине. Используйте «Пополнить» для существующей партии.',
        ];
    }
}
