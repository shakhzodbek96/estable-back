<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvestmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInvestorRequest;
use App\Http\Requests\Admin\UpdateInvestorRequest;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvestorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Investor::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        $perPage = max(1, min($request->integer('per_page', 15), 100));

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(StoreInvestorRequest $request): JsonResponse
    {
        $investor = DB::transaction(function () use ($request) {
            $investor = Investor::create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
            ]);

            $balance = (float) ($request->validated()['balance'] ?? 0);

            if ($balance > 0) {
                $rate = Rate::current();

                Investment::create([
                    'investor_id' => $investor->id,
                    'type' => InvestmentType::Investment,
                    'is_credit' => true,
                    'amount' => $balance,
                    'rate' => $rate?->rate ?? 0,
                    'comment' => 'Boshlang\'ich sarmoya',
                    'created_by' => $request->user()->id,
                ]);
            }

            return $investor;
        });

        return response()->json($investor, 201);
    }

    public function show(Investor $investor): JsonResponse
    {
        $investor->load(['investments' => function ($q) {
            $q->latest()->limit(50);
        }]);

        return response()->json($investor);
    }

    public function update(UpdateInvestorRequest $request, Investor $investor): JsonResponse
    {
        $investor->update($request->validated());

        return response()->json($investor);
    }

    public function destroy(Investor $investor): JsonResponse
    {
        $investor->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
