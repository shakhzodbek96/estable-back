<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with([
            'shop:id,name',
            'investor:id,name',
            'creator:id,name',
            'accepter:id,name',
        ]);

        if ($type = $request->string('type')->trim()->value()) {
            $query->where('type', $type);
        }

        if ($investorId = $request->integer('investor_id')) {
            $query->where('investor_id', $investorId);
        }

        if ($shopId = $request->integer('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        if ($isCredit = $request->string('is_credit')->trim()->value()) {
            $query->where('is_credit', $isCredit === 'true' || $isCredit === '1');
        }

        if ($dateFrom = $request->string('date_from')->trim()->value()) {
            $query->where(function ($q) use ($dateFrom) {
                $q->whereDate('transaction_date', '>=', $dateFrom)
                    ->orWhere(function ($q2) use ($dateFrom) {
                        $q2->whereNull('transaction_date')->whereDate('created_at', '>=', $dateFrom);
                    });
            });
        }

        if ($dateTo = $request->string('date_to')->trim()->value()) {
            $query->where(function ($q) use ($dateTo) {
                $q->whereDate('transaction_date', '<=', $dateTo)
                    ->orWhere(function ($q2) use ($dateTo) {
                        $q2->whereNull('transaction_date')->whereDate('created_at', '<=', $dateTo);
                    });
            });
        }

        $perPage = max(1, min($request->integer('per_page', 25), 100));

        return response()->json(
            $query->orderByDesc('created_at')->paginate($perPage)
        );
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load([
            'shop:id,name',
            'investor:id,name',
            'creator:id,name',
            'accepter:id,name',
        ]);

        return response()->json($transaction);
    }
}
