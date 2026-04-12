<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Return\StoreReturnRequest;
use App\Models\Return_;
use App\Models\Sale;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function __construct(
        private ReturnService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Return_::with([
            'sale:id,sale_date,total_price,customer_id,sold_by',
            'sale.customer:id,name',
            'sale.seller:id,name',
            'saleItem.inventory.product:id,name',
            'saleItem.accessory.product:id,name',
            'customer:id,name,phone',
            'creator:id,name',
            'approver:id,name',
        ]);

        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->string('date_from')->trim()->value()) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->trim()->value()) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = max(1, min($request->integer('per_page', 20), 100));

        return response()->json(
            $query->orderByDesc('created_at')->paginate($perPage)
        );
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        try {
            $return = $this->service->create($request->validated());
            $return->load([
                'sale:id,sale_date,total_price',
                'saleItem.inventory.product:id,name',
                'saleItem.accessory.product:id,name',
                'customer:id,name',
            ]);
            return response()->json($return, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Return_ $return): JsonResponse
    {
        $return->load([
            'sale.customer',
            'sale.seller:id,name',
            'sale.shop:id,name',
            'sale.items.inventory.product:id,name',
            'sale.items.accessory.product:id,name',
            'saleItem.inventory.product:id,name',
            'saleItem.accessory.product:id,name',
            'customer',
            'creator:id,name',
            'approver:id,name',
            'shop:id,name',
        ]);

        return response()->json($return);
    }

    public function approve(Return_ $return): JsonResponse
    {
        try {
            $result = $this->service->approve($return);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, Return_ $return): JsonResponse
    {
        try {
            $result = $this->service->reject($return, $request->input('reason'));
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function bySale(Sale $sale): JsonResponse
    {
        $returns = Return_::with([
            'saleItem.inventory.product:id,name',
            'saleItem.accessory.product:id,name',
            'creator:id,name',
            'approver:id,name',
        ])
        ->where('sale_id', $sale->id)
        ->orderByDesc('created_at')
        ->get();

        return response()->json($returns);
    }
}
