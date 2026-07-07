<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Controller;
use App\Models\SupplyBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplyBatchController extends Controller
{
    /**
     * Партиялар (накладнойлар) ro'yxati — dropdown/filtr uchun.
     * Har birida in_stock donalar soni ko'rsatiladi.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SupplyBatch::query()
            ->with('supplier:id,name')
            ->withCount(['inventories as in_stock_count' => fn ($q) => $q->where('status', InventoryStatus::InStock)]);

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                  ->orWhere('supplier_name', 'ilike', "%{$search}%")
                  ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'ilike', "%{$search}%"));
            });
        }

        $limit = max(1, min($request->integer('limit', 50), 200));

        $batches = $query->orderByDesc('batch_date')->orderByDesc('id')->limit($limit)->get();

        return response()->json([
            'items' => $batches->map(fn (SupplyBatch $batch) => [
                'id' => $batch->id,
                'invoice_number' => $batch->invoice_number,
                'batch_date' => $batch->batch_date?->toDateString(),
                'supplier_name' => $batch->supplier?->name ?? $batch->supplier_name,
                'in_stock_count' => $batch->in_stock_count,
            ]),
        ]);
    }
}
