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
 *   - barcode'lar bitta so'rov ichida unique (distinct) — bir накладной ichida
 *     tasodifiy takrorni oldini olish uchun
 *   - bazada esa bir barcode bo'yicha bir nechta partiya (har biri o'z narxi/investori
 *     bilan) bo'lishi MUMKIN — aksessuar fungible/partiyali tovar, sotuvda FIFO
 *     (AccessoryService::findForSale). Shuning uchun DB bo'yicha unique tekshiruvi YO'Q.
 */
class BulkStoreAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Shared
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'investor_id' => ['nullable', 'integer', 'exists:investors,id'],
            // Партия / Поставщик (ixtiyoriy). Nasiya (credit) faqat saqlangan postavshik bilan.
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id', Rule::requiredIf(fn () => $this->input('payment_mode') === 'credit')],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'batch_date' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'in:paid,credit'],
            'batch_notes' => ['nullable', 'string', 'max:1000'],

            // Batches array
            'batches' => ['required', 'array', 'min:1'],
            'batches.*.barcode' => ['required', 'string', 'max:255', 'distinct'],
            'batches.*.quantity' => ['required', 'integer', 'min:1'],
            'batches.*.purchase_price' => ['required', 'numeric', 'min:0'],
            'batches.*.sell_price' => ['required', 'numeric', 'min:0'],
            'batches.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'batches.*.notes' => ['nullable', 'string', 'max:1000'],
            // Dinamik xususiyatlar — barcha partiyalarga umumiy
            'custom_attributes' => ['nullable', 'array'],
            'custom_attributes.*.id' => ['required', 'integer', 'exists:attribute_definitions,id'],
            'custom_attributes.*.value' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'batches.*.barcode.distinct' => 'Штрих-код :input дублируется в списке.',
            'supplier_id.required' => 'Для покупки в долг выберите поставщика.',
        ];
    }
}
