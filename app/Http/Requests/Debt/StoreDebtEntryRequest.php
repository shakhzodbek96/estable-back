<?php

namespace App\Http\Requests\Debt;

use App\Enums\Currency;
use App\Enums\DebtEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDebtEntryRequest extends FormRequest
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
            'type' => ['required', new Enum(DebtEntryType::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', new Enum(Currency::class)],
            'comment' => ['nullable', 'string', 'max:255'],
            'entry_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:entry_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'Сумма должна быть больше нуля',
        ];
    }
}
