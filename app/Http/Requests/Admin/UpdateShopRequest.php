<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'yandex_maps_url' => ['nullable', 'url', 'max:2000'],
            'google_maps_url' => ['nullable', 'url', 'max:2000'],
            'working_hours' => ['nullable', 'array'],
            'working_hours.*' => ['array:open,close,closed'],
            'working_hours.*.closed' => ['boolean'],
            'working_hours.*.open' => ['nullable', 'date_format:H:i'],
            'working_hours.*.close' => ['nullable', 'date_format:H:i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->working_hours)) {
            return;
        }

        $allowed = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $this->merge([
            'working_hours' => collect($this->working_hours)
                ->only($allowed)
                ->all(),
        ]);
    }
}
