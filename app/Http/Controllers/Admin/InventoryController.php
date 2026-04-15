<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BulkStoreInventoryRequest;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name']);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'ilike', "%{$search}%")
                  ->orWhere('extra_serial_number', 'ilike', "%{$search}%");
            });
        }

        if ($productSearch = $request->string('product_search')->trim()->value()) {
            $query->whereHas('product', function ($q) use ($productSearch) {
                $q->where('name', 'ilike', "%{$productSearch}%");
            });
        }

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($investorId = $request->integer('investor_id')) {
            $query->where('investor_id', $investorId);
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $data = [
            ...$request->validated(),
            'serials' => [['serial_number' => $request->serial_number, 'extra_serial_number' => $request->extra_serial_number]],
        ];

        $inventories = $this->inventoryService->createBatch($data);

        return response()->json($inventories->first()->load(['product:id,name', 'shop:id,name']), 201);
    }

    public function bulkStore(BulkStoreInventoryRequest $request): JsonResponse
    {
        $inventories = $this->inventoryService->createBatch($request->validated());

        $ids = $inventories->pluck('id');
        $loaded = Inventory::with(['product:id,name', 'shop:id,name'])->whereIn('id', $ids)->get();

        return response()->json([
            'message' => $loaded->count() . ' ta tovar yaratildi',
            'data' => $loaded,
        ], 201);
    }

    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load([
            'product:id,name,type,category_id',
            'product.category:id,name',
            'shop:id,name',
            'investor:id,name,phone',
            'creator:id,name',
            'repairCosts' => fn ($q) => $q->orderByDesc('id'),
        ]);

        return response()->json($inventory);
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventory): JsonResponse
    {
        $inventory->update($request->validated());
        $inventory->load(['product:id,name', 'shop:id,name', 'investor:id,name']);

        return response()->json($inventory);
    }

    public function destroy(Inventory $inventory): JsonResponse
    {
        if ($inventory->status !== InventoryStatus::InStock) {
            return response()->json(['message' => 'Faqat in_stock tovarni o\'chirish mumkin'], 422);
        }

        $inventory->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function search(Request $request): JsonResponse
    {
        $serial = $request->string('serial')->trim()->value();

        if (!$serial) {
            return response()->json(['data' => []]);
        }

        $results = Inventory::with(['product:id,name,type', 'shop:id,name'])
            ->where('serial_number', 'ilike', "%{$serial}%")
            ->orWhere('extra_serial_number', 'ilike', "%{$serial}%")
            ->limit(20)
            ->get();

        return response()->json(['data' => $results]);
    }

    public function byStatus(string $status): JsonResponse
    {
        $inventories = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name'])
            ->where('status', $status)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($inventories);
    }
}
