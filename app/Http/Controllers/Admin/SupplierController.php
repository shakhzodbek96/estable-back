<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSupplierRequest;
use App\Http\Requests\Admin\UpdateSupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()->withCount('batches');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($supplier, 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->loadCount('batches');
        $supplier->load([
            'batches' => fn ($q) => $q->latest()->limit(20)
                ->withCount(['inventories', 'accessories']),
            'payments' => fn ($q) => $q->latest()->limit(20)->with('creator:id,name'),
        ]);

        return response()->json($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        // Qarzi bor postavshikni o'chirib bo'lmaydi (hisob-kitob buzilmasin).
        if (abs((float) $supplier->balance) > 0.01) {
            return response()->json([
                'message' => 'Нельзя удалить поставщика с ненулевым долгом. Сначала погасите баланс.',
            ], 422);
        }

        $supplier->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
