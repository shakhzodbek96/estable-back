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
            'payments.*.comment' => ['nullable', 'string', 'max:500'],

            // P2P uchun qo'shimcha ma'lumotlar (audit uchun):
            //   - card_last4: karta oxirgi 4 raqami
            //   - time: to'lov vaqti (hh:mm)
            'payments.*.details' => ['nullable', 'array'],
            'payments.*.details.card_last4' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'payments.*.details.time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'payments.*.details.card_last4.regex' => 'Последние 4 цифры карты должны быть числом.',
            'payments.*.details.time.regex' => 'Время должно быть в формате ЧЧ:ММ.',
        ];
    }
}
