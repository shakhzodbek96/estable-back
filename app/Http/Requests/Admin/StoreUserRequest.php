<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'is_blocked' => ['sometimes', 'boolean'],
            'role' => ['required', new Enum(UserRole::class)],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Телефон должен содержать ровно 9 цифр (например 991234567).',
        ];
    }
}
