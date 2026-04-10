<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'is_blocked' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'required', new Enum(UserRole::class)],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'password' => ['nullable', 'string', 'min:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Телефон должен содержать ровно 9 цифр (например 991234567).',
        ];
    }
}
