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
            // Aksessuar — partiyali (fungible) tovar: bir barcode bo'yicha bir nechta partiya
            // (har biri o'z narxi/накладной/investori bilan) bo'lishi mumkin. Sotuvda FIFO
            // (AccessoryService::findForSale — eng eski qoldig'i bor partiya). Shuning uchun
            // barcode unique EMAS. "Пополнить" — narx o'zgarmaganda mavjud partiyaga qoldiq qo'shish.
            'barcode' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
            'custom_attributes' => ['nullable', 'array'],
            'custom_attributes.*.id' => ['required', 'integer', 'exists:attribute_definitions,id'],
            'custom_attributes.*.value' => ['nullable'],
        ];
    }
}
