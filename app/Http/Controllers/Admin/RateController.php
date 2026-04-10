<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRateRequest;
use App\Models\Rate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $rates = Rate::with('creator:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($rates);
    }

    public function current(): JsonResponse
    {
        $rate = Rate::current();

        if (!$rate) {
            return response()->json(['message' => 'Kurs topilmadi'], 404);
        }

        return response()->json($rate);
    }

    public function store(StoreRateRequest $request): JsonResponse
    {
        $rate = Rate::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $rate->load('creator:id,name');

        return response()->json($rate, 201);
    }
}
