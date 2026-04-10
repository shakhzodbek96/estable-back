<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePartnerRequest;
use App\Http\Requests\Admin\UpdatePartnerRequest;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Partner::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StorePartnerRequest $request): JsonResponse
    {
        $partner = Partner::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($partner, 201);
    }

    public function show(Partner $partner): JsonResponse
    {
        $partner->load(['consignments' => function ($q) {
            $q->with('items')->latest()->limit(20);
        }]);

        return response()->json($partner);
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): JsonResponse
    {
        $partner->update($request->validated());

        return response()->json($partner);
    }

    public function destroy(Partner $partner): JsonResponse
    {
        $partner->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
