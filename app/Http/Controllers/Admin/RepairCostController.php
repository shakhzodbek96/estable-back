<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RepairCost\StoreRepairCostRequest;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class RepairCostController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    public function index(Inventory $inventory): JsonResponse
    {
        $costs = $inventory->repairCosts()
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $costs]);
    }

    public function store(StoreRepairCostRequest $request, Inventory $inventory): JsonResponse
    {
        $cost = $this->inventoryService->addRepairCost($inventory, $request->validated());
        $cost->load('creator:id,name');

        return response()->json($cost, 201);
    }
}
