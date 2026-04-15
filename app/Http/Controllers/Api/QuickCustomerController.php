<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuickCustomerController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'phone' => preg_replace('/\D+/', '', (string) $request->input('phone')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'digits:9', 'unique:customers,phone'],
        ], [
            'phone.digits' => 'Телефон должен содержать ровно 9 цифр (формат: 901234567)',
            'phone.unique' => 'Клиент с таким телефоном уже существует',
        ]);

        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }
}
