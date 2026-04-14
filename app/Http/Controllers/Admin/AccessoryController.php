<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accessory\RestockAccessoryRequest;
use App\Http\Requests\Accessory\StoreAccessoryRequest;
use App\Http\Requests\Accessory\UpdateAccessoryRequest;
use App\Models\Accessory;
use App\Services\AccessoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessoryController extends Controller
{
    public function __construct(
        private AccessoryService $accessoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Accessory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name']);

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($barcode = $request->string('barcode')->trim()->value()) {
            $query->where('barcode', 'ilike', "%{$barcode}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($investorId = $request->integer('investor_id')) {
            $query->where('investor_id', $investorId);
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreAccessoryRequest $request): JsonResponse
    {
        $accessory = $this->accessoryService->createBatch($request->validated());
        $accessory->load(['product:id,name', 'shop:id,name']);

        return response()->json($accessory, 201);
    }

    public function show(Accessory $accessory): JsonResponse
    {
        $accessory->load([
            'product:id,name,type,category_id',
            'product.category:id,name',
            'shop:id,name',
            'investor:id,name,phone',
            'creator:id,name',
        ]);

        return response()->json($accessory);
    }

    public function update(UpdateAccessoryRequest $request, Accessory $accessory): JsonResponse
    {
        $accessory->update($request->validated());
        $accessory->load(['product:id,name', 'shop:id,name']);

        return response()->json($accessory);
    }

    public function destroy(Accessory $accessory): JsonResponse
    {
        if ($accessory->sold_quantity > 0) {
            return response()->json(['message' => 'Sotilgan partiyani o\'chirish mumkin emas'], 422);
        }

        $accessory->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function search(Request $request): JsonResponse
    {
        $barcode = $request->string('barcode')->trim()->value();
        $shopId = $request->integer('shop_id');

        if (!$barcode) {
            return response()->json(['data' => null]);
        }

        $accessory = $shopId
            ? $this->accessoryService->findForSale($barcode, $shopId)
            : Accessory::with('product:id,name')->where('barcode', $barcode)->where('is_active', true)->first();

        return response()->json(['data' => $accessory?->load('product:id,name')]);
    }

    public function restock(RestockAccessoryRequest $request, Accessory $accessory): JsonResponse
    {
        $accessory = $this->accessoryService->restock($accessory, $request->validated()['quantity']);
        $accessory->load(['product:id,name', 'shop:id,name']);

        return response()->json($accessory);
    }
}
