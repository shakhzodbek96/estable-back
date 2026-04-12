<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryStatusController extends Controller
{
    public function __construct(
        private ReturnService $service
    ) {}

    public function sendToRepair(Inventory $inventory): JsonResponse
    {
        try {
            $result = $this->service->sendToRepair($inventory);
            return response()->json($result->load('product:id,name'));
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function returnFromRepair(Inventory $inventory): JsonResponse
    {
        try {
            $result = $this->service->returnFromRepair($inventory);
            return response()->json($result->load('product:id,name'));
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function writeOff(Request $request, Inventory $inventory): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            $result = $this->service->writeOff($inventory, $request->input('reason'));
            return response()->json($result->load('product:id,name'));
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
