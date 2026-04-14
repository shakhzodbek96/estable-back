<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvestmentType;
use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvestmentController extends Controller
{
    /**
     * Investor bo'yicha investitsiya/divident yozuvlari ro'yxati.
     * Filter: investor_id (majburiy), type, date_from, date_to
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'investor_id' => 'required|integer|exists:investors,id',
            'type' => ['nullable', Rule::in([1, 2])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $q = Investment::query()
            ->where('investor_id', $request->integer('investor_id'))
            ->with(['creator:id,name'])
            ->latest('id');

        if ($type = $request->integer('type')) {
            $q->where('type', $type);
        }
        if ($df = $request->input('date_from')) {
            $q->whereDate('created_at', '>=', $df);
        }
        if ($dt = $request->input('date_to')) {
            $q->whereDate('created_at', '<=', $dt);
        }

        return response()->json($q->paginate($request->integer('per_page', 15)));
    }

    /**
     * Investor bo'yicha barcha vaqtdagi jami summalar (type bo'yicha).
     * GET /api/investments/totals?investor_id=X
     */
    public function totals(Request $request): JsonResponse
    {
        $request->validate([
            'investor_id' => 'required|integer|exists:investors,id',
        ]);

        $rows = Investment::where('investor_id', $request->integer('investor_id'))
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return response()->json([
            'investment' => (float) ($rows[1] ?? 0),
            'dividend' => (float) ($rows[2] ?? 0),
            'clients_payment' => (float) ($rows[3] ?? 0),
            'buying_product' => (float) ($rows[4] ?? 0),
        ]);
    }

    /**
     * Yangi yozuv qo'shish (1=Investitsiya +, 2=Divident -).
     * Investor balansini avtomatik yangilaydi.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'investor_id' => 'required|integer|exists:investors,id',
            'type' => ['required', Rule::in([1, 2])],
            'amount' => 'required|numeric|min:0.01',
            'comment' => 'nullable|string|max:1000',
        ]);

        $investment = DB::transaction(function () use ($data, $request) {
            $investor = Investor::lockForUpdate()->findOrFail($data['investor_id']);
            $type = InvestmentType::from((int) $data['type']);
            $isCredit = $type === InvestmentType::Investment; // +investment, -dividend

            $rate = Rate::current();

            $investment = Investment::create([
                'investor_id' => $investor->id,
                'type' => $type,
                'is_credit' => $isCredit,
                'amount' => $data['amount'],
                'rate' => $rate?->rate ?? 0,
                'comment' => $data['comment'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $delta = (float) $data['amount'] * ($isCredit ? 1 : -1);
            $investor->balance = (float) $investor->balance + $delta;
            $investor->save();

            return $investment->load('creator:id,name');
        });

        return response()->json($investment, 201);
    }

    /**
     * Mavjud yozuvni tahrirlash (summa/izoh/tur).
     */
    public function update(Request $request, Investment $investment): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in([1, 2])],
            'amount' => 'sometimes|numeric|min:0.01',
            'comment' => 'nullable|string|max:1000',
        ]);

        $updated = DB::transaction(function () use ($investment, $data) {
            $investor = Investor::lockForUpdate()->findOrFail($investment->investor_id);

            // Eski ta'sirni qaytarish
            $oldDelta = (float) $investment->amount * ($investment->is_credit ? 1 : -1);
            $investor->balance = (float) $investor->balance - $oldDelta;

            // Yangi qiymatlar
            $newType = isset($data['type'])
                ? InvestmentType::from((int) $data['type'])
                : $investment->type;
            $newIsCredit = $newType === InvestmentType::Investment;
            $newAmount = (float) ($data['amount'] ?? $investment->amount);

            $investment->fill([
                'type' => $newType,
                'is_credit' => $newIsCredit,
                'amount' => $newAmount,
                'comment' => array_key_exists('comment', $data) ? $data['comment'] : $investment->comment,
            ])->save();

            $newDelta = $newAmount * ($newIsCredit ? 1 : -1);
            $investor->balance = (float) $investor->balance + $newDelta;
            $investor->save();

            return $investment->load('creator:id,name');
        });

        return response()->json($updated);
    }

    /**
     * Yozuvni o'chirish — investor balansidan ta'sirni qaytaradi.
     */
    public function destroy(Investment $investment): JsonResponse
    {
        DB::transaction(function () use ($investment) {
            $investor = Investor::lockForUpdate()->findOrFail($investment->investor_id);
            $delta = (float) $investment->amount * ($investment->is_credit ? 1 : -1);
            $investor->balance = (float) $investor->balance - $delta;
            $investor->save();
            $investment->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }
}
