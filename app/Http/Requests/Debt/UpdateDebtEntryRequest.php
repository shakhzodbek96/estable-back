<?php

namespace App\Http\Requests\Debt;

use App\Enums\Currency;
use App\Enums\DebtEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDebtEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        if ($this->has('amount')) {
            $this->merge(['amount' => str_replace(',', '', (string) $this->input('amount'))]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', new Enum(DebtEntryType::class)],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'currency' => ['sometimes', 'required', new Enum(Currency::class)],
            'comment' => ['nullable', 'string', 'max:255'],
            'entry_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'Сумма должна быть больше нуля',
        ];
    }
}
