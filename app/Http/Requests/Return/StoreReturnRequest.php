<?php

namespace App\Http\Requests\Return;

use App\Enums\ItemCondition;
use App\Enums\ReturnReason;
use App\Enums\ReturnType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_item_id' => 'required|integer|exists:sale_items,id',
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'reason_note' => 'nullable|string|max:1000',
            'return_type' => ['required', Rule::enum(ReturnType::class)],
            'refund_amount' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|string|in:cash,card,p2p',
            'price_difference' => 'nullable|numeric',
            'item_condition' => ['required', Rule::enum(ItemCondition::class)],
            'transfers_to_shop' => 'boolean',
        ];
    }
}
