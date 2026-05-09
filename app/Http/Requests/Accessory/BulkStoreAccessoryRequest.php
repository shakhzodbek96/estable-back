<?php

namespace App\Http\Requests\Accessory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bir nechta aksessuar partiyasini bitta so'rov bilan yaratish.
 *
 * Shared fields (common for all batches):
 *   - product_id, shop_id, invoice_number, investor_id
 *
 * Per-batch fields (har partiya uchun alohida):
 *   - barcode, quantity, purchase_price, sell_price, wholesale_price, notes
 *
 * Validatsiya:
 *   - barcode'lar so'rov ichida unique (distinct)
 *   - barcode shu do'kon doirasida (shop_id) bazada is_active=true bo'lib mavjud
 *     emasligi tekshiriladi — bo'lsa, mavjud partiyani restock qilish kerak
 */
class BulkStoreAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shopId = $this->input('shop_id');

        return [
            // Shared
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],

            // Batches array
            'batches' => ['required', 'array', 'min:1'],
            'batches.*.barcode' => [
                'required', 'string', 'max:255', 'distinct',
                Rule::unique('accessories', 'barcode')->where(function ($q) use ($shopId) {
                    $q->where('shop_id', $shopId)->where('is_active', true);
                }),
            ],
            'batches.*.quantity' => ['required', 'integer', 'min:1'],
            'batches.*.purchase_price' => ['required', 'numeric', 'min:0'],
            'batches.*.sell_price' => ['required', 'numeric', 'min:0'],
            'batches.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'batches.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'batches.*.barcode.distinct' => 'Штрих-код :input дублируется в списке.',
            'batches.*.barcode.unique'   => 'Штрих-код :input уже существует в этом магазине. Используйте «Пополнить» для существующей партии.',
        ];
    }
}
