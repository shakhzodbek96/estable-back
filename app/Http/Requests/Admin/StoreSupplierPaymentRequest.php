<?php

namespace App\Http\Requests\Admin;

use App\Services\SupplierPaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', Rule::in(['usd', 'uzs'])],
            'rate' => ['nullable', 'numeric', 'min:0', 'required_if:currency,uzs'],
            'payment_method' => ['required', Rule::in(SupplierPaymentService::ALLOWED_METHODS)],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'supply_batch_id' => ['nullable', 'integer', 'exists:supply_batches,id'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Укажите сумму оплаты',
            'amount.min' => 'Сумма должна быть больше нуля',
            'currency.required' => 'Укажите валюту',
            'currency.in' => 'Недопустимая валюта',
            'rate.required_if' => 'Укажите курс для оплаты в сумах',
            'payment_method.required' => 'Укажите способ оплаты',
            'payment_method.in' => 'Недопустимый способ оплаты',
            'shop_id.required' => 'Укажите магазин',
            'shop_id.exists' => 'Магазин не найден',
            'supply_batch_id.exists' => 'Партия не найдена',
        ];
    }
}
