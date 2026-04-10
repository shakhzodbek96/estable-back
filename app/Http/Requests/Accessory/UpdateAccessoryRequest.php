<?php

namespace App\Http\Requests\Accessory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'barcode' => ['sometimes', 'required', 'string', 'max:255'],
            'sell_price' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
