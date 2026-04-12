<?php

namespace App\Http\Requests\Consignment;

use App\Enums\ConsignmentDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $direction = $this->input('direction');

        $rules = [
            'partner_id' => 'required|integer|exists:partners,id',
            'direction' => ['required', Rule::enum(ConsignmentDirection::class)],
            'deadline' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:serial,bulk',
            'items.*.agreed_price' => 'required|numeric|min:0.01',
            'items.*.quantity' => 'integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',
        ];

        if ($direction === 'outgoing') {
            $rules['items.*.inventory_id'] = 'required_if:items.*.item_type,serial|integer|exists:inventories,id';
            $rules['items.*.accessory_id'] = 'required_if:items.*.item_type,bulk|integer|exists:accessories,id';
        } else {
            // Incoming — yangi tovar yaratiladi
            $rules['items.*.product_id'] = 'required|integer|exists:products,id';
            $rules['items.*.serial_number'] = 'required_if:items.*.item_type,serial|string';
            $rules['items.*.selling_price'] = 'required_if:items.*.item_type,serial|numeric|min:0';
            $rules['items.*.barcode'] = 'required_if:items.*.item_type,bulk|string';
            $rules['items.*.sell_price'] = 'required_if:items.*.item_type,bulk|numeric|min:0';
        }

        return $rules;
    }
}
