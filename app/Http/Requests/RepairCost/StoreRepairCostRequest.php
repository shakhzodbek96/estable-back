<?php

namespace App\Http\Requests\RepairCost;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepairCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:1000'],
            'repaired_by' => ['nullable', 'string', 'max:255'],
            'repaired_at' => ['nullable', 'date'],
        ];
    }
}
