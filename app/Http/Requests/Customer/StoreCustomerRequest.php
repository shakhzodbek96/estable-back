<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        // Faqat raqamlarni qoldirish (frontend formatlarini olib tashlash)
        if ($this->has('phone')) {
            $this->merge([
                'phone' => preg_replace('/\D+/', '', (string) $this->input('phone')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'digits:9', 'unique:customers,phone'],
            'is_wholesale' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.digits' => 'Телефон должен содержать ровно 9 цифр (формат: 901234567)',
            'phone.unique' => 'Клиент с таким телефоном уже существует',
        ];
    }
}
