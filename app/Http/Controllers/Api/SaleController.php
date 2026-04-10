<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $saleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Sale::with(['customer:id,name,phone', 'seller:id,name', 'shop:id,name'])
            ->withCount('items');

        if ($dateFrom = $request->string('date_from')->trim()->value()) {
            $query->whereDate('sale_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->trim()->value()) {
            $query->whereDate('sale_date', '<=', $dateTo);
        }

        if ($sellerId = $request->integer('seller_id')) {
            $query->where('sold_by', $sellerId);
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json(
            $query->orderByDesc('sale_date')->orderByDesc('created_at')->paginate($perPage)
        );
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        try {
            $sale = $this->saleService->create($request->validated());
            return response()->json($sale, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Sale $sale): JsonResponse
    {
        $sale->load([
            'customer',
            'seller:id,name',
            'shop:id,name',
            'items.inventory.product:id,name',
            'items.accessory.product:id,name',
            'payments.creator:id,name',
        ]);

        return response()->json($sale);
    }

    public function destroy(Sale $sale): JsonResponse
    {
        $allNew = $sale->payments()->where('status', '!=', 'new')->count() === 0;
        $isOwner = $sale->sold_by === auth()->id();
        $isAdmin = auth()->user()->role->value === 'admin';

        if (!$allNew) {
            return response()->json(['message' => 'Tasdiqlangan to\'lovlar bor, o\'chirish mumkin emas'], 422);
        }

        if (!$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Faqat yaratuvchi yoki admin o\'chirishi mumkin'], 403);
        }

        // Revert inventory/accessory changes
        foreach ($sale->items as $item) {
            if ($item->item_type->value === 'serial' && $item->inventory) {
                $item->inventory->update([
                    'status' => 'in_stock',
                    'sold_price' => null,
                    'sold_at' => null,
                ]);
            } elseif ($item->item_type->value === 'bulk' && $item->accessory) {
                $item->accessory->decrement('sold_quantity', $item->quantity);
                $accessory = $item->accessory->fresh();
                if ($accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity > 0) {
                    $accessory->update(['is_active' => true]);
                }
            }
        }

        $sale->payments()->delete();
        $sale->items()->delete();
        $sale->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
