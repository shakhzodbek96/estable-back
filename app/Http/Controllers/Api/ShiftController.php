<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\KassaExpense;
use App\Models\SalePayment;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(
        private ShiftService $service
    ) {}

    /**
     * Do'kon uchun joriy ochiq smena + kutilayotgan naqд (real-time сверка).
     */
    public function current(Request $request): JsonResponse
    {
        $shopId = $request->integer('shop_id');
        if (! $shopId) {
            return response()->json(['shift' => null]);
        }

        $shift = CashShift::openForShop($shopId);
        if (! $shift) {
            return response()->json(['shift' => null]);
        }

        $shift->load(['opener:id,name', 'shop:id,name']);

        return response()->json([
            'shift' => $shift,
            'expected_cash' => $this->service->expectedCash($shift),
        ]);
    }

    /**
     * Smena tafsiloti — ichidagi sotuvlar va rasxodlar bilan.
     */
    public function show(CashShift $shift): JsonResponse
    {
        $shift->load(['opener:id,name', 'closer:id,name', 'shop:id,name']);

        $payments = SalePayment::where('shift_id', $shift->id)
            ->with(['sale:id,sale_date,total_price', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $expenses = KassaExpense::where('shift_id', $shift->id)
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        // Ochiq smena uchun jonli hisob, yopilgani uchun saqlangan qiymat
        $expected = $shift->status === ShiftStatus::Open
            ? $this->service->expectedCash($shift)
            : ($shift->expected_cash ?? ['usd' => 0, 'uzs' => 0]);

        return response()->json([
            'shift' => $shift,
            'expected_cash' => $expected,
            'sale_payments' => $payments,
            'expenses' => $expenses,
        ]);
    }

    /**
     * Smenalar tarixi.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CashShift::with(['opener:id,name', 'closer:id,name', 'shop:id,name']);

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }
        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }

        $perPage = max(1, min($request->integer('per_page', 30), 100));

        return response()->json($query->orderByDesc('opened_at')->paginate($perPage));
    }

    /**
     * Smena ochish (menejer yoki admin).
     */
    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
        ]);

        try {
            $shift = $this->service->open($validated['shop_id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($shift->load('opener:id,name'), 201);
    }

    /**
     * Smena yopish — naqд sanab qabul qilish (faqat admin/rahbar).
     */
    public function close(Request $request, CashShift $shift): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'array'],
            'counted_cash.usd' => ['nullable', 'numeric', 'min:0'],
            'counted_cash.uzs' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->service->close(
                $shift,
                $validated['counted_cash'],
                $validated['comment'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
