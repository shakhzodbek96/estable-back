<?php

namespace App\Http\Requests\Debt;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDebtContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $digits = preg_replace('/\D+/', '', (string) $this->input('phone'));
            $this->merge(['phone' => $digits === '' ? null : $digits]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'digits:9'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя',
            'phone.digits' => 'Телефон должен содержать ровно 9 цифр (формат: 901234567)',
        ];
    }
}
