<?php

namespace App\Http\Controllers\Api;

use App\Enums\SalePaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalePaymentController extends Controller
{
    public function __construct(
        private SalePaymentService $service
    ) {}

    /**
     * Tasdiqlanmagan to'lovlar ro'yxati
     */
    public function pending(Request $request): JsonResponse
    {
        $query = SalePayment::with([
            'sale:id,customer_id,sale_date,total_price,sold_by,shop_id',
            'sale.customer:id,name,phone',
            'sale.seller:id,name',
            'sale.shop:id,name',
            'sale.items.inventory.product:id,name',
            'sale.items.accessory.product:id,name',
            'creator:id,name',
            'shop:id,name',
        ])
        ->where('status', SalePaymentStatus::New);

        if ($sellerId = $request->integer('seller_id')) {
            $query->where('created_by', $sellerId);
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($dateFrom = $request->string('date_from')->trim()->value()) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->trim()->value()) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = max(1, min($request->integer('per_page', 50), 200));

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($perPage)
        );
    }

    /**
     * To'lov tafsilotlari
     */
    public function show(SalePayment $salePayment): JsonResponse
    {
        $salePayment->load([
            'sale.customer',
            'sale.seller:id,name',
            'sale.shop:id,name',
            'sale.items.inventory.product:id,name',
            'sale.items.accessory.product:id,name',
            'sale.payments.creator:id,name',
            'creator:id,name',
            'checker:id,name',
            'investor:id,name',
            'transaction',
        ]);

        return response()->json($salePayment);
    }

    /**
     * To'lovni tasdiqlash
     */
    public function accept(SalePayment $salePayment): JsonResponse
    {
        try {
            $payment = $this->service->accept($salePayment);
            return response()->json($payment);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * To'lovni rad etish
     */
    public function reject(Request $request, SalePayment $salePayment): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $payment = $this->service->reject($salePayment, $request->input('reason'));
            return response()->json($payment);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Bir nechta to'lovni bir vaqtda tasdiqlash
     */
    public function bulkAccept(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:sale_payments,id',
        ]);

        $results = $this->service->bulkAccept($request->input('ids'));

        return response()->json($results);
    }

    /**
     * Sotuvchi kassa hisoboti
     */
    public function cashSummary(Request $request, User $user): JsonResponse
    {
        $dateFrom = $request->string('date_from')->trim()->value() ?: null;
        $dateTo = $request->string('date_to')->trim()->value() ?: null;

        $summary = $this->service->getCashSummary($user->id, $dateFrom, $dateTo);

        return response()->json([
            'seller' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            ...$summary,
        ]);
    }
}
