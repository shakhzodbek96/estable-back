<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExpenseStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\KassaExpense;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseService $service
    ) {}

    /**
     * Chiqimlar tarixi.
     */
    public function index(Request $request): JsonResponse
    {
        $query = KassaExpense::with(['shop:id,name', 'creator:id,name', 'checker:id,name']);

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }
        if ($status = $request->string('status')->trim()->value()) {
            $query->where('status', $status);
        }
        if ($from = $request->string('date_from')->trim()->value()) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('date_to')->trim()->value()) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = max(1, min($request->integer('per_page', 50), 200));

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    /**
     * Tasdiqlanmagan chiqimlar (admin tasdiqlashi uchun).
     */
    public function pending(Request $request): JsonResponse
    {
        $query = KassaExpense::with(['shop:id,name', 'creator:id,name'])
            ->where('status', ExpenseStatus::New);

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        return response()->json($query->orderByDesc('created_at')->get());
    }

    /**
     * Yangi chiqim — pending bo'lib yoziladi (menejer yoki admin).
     */
    public function store(Request $request): JsonResponse
    {
        $allowedTypes = array_map(fn ($t) => $t->value, ExpenseService::ALLOWED_TYPES);

        $validated = $request->validate([
            'type' => ['required', Rule::in($allowedTypes)],
            'method' => ['required', Rule::in(ExpenseService::ALLOWED_METHODS)],
            'currency' => ['required', Rule::in(['usd', 'uzs'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'comment' => ['nullable', 'string', 'max:500'],
            // P2P uchun ixtiyoriy karta ma'lumotlari (audit)
            'details' => ['nullable', 'array'],
            'details.card_last4' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'details.time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        // Karta ma'lumotlari faqat P2P uchun saqlanadi (boshqa usulda e'tiborsiz)
        if (($validated['method'] ?? null) !== 'p2p') {
            unset($validated['details']);
        }

        $validated['type'] = TransactionType::from($validated['type']);

        try {
            $expense = $this->service->create($validated);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($expense->load(['shop:id,name', 'creator:id,name']), 201);
    }

    /**
     * Chiqimni tasdiqlash (faqat admin) — ledger'ga yoziladi.
     */
    public function accept(KassaExpense $expense): JsonResponse
    {
        try {
            $result = $this->service->accept($expense);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result->load(['shop:id,name', 'creator:id,name', 'checker:id,name']));
    }

    /**
     * Chiqimni rad etish (faqat admin).
     */
    public function reject(Request $request, KassaExpense $expense): JsonResponse
    {
        $reason = $request->string('reason')->trim()->value() ?: null;

        try {
            $result = $this->service->reject($expense, $reason);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result->load(['shop:id,name', 'creator:id,name', 'checker:id,name']));
    }
}
