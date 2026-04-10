<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Inventory;
use App\Services\AccessoryService;
use Illuminate\Http\JsonResponse;

class SaleScanController extends Controller
{
    public function scanImei(string $imei): JsonResponse
    {
        $inventory = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name'])
            ->where('status', 'in_stock')
            ->where(function ($q) use ($imei) {
                $q->where('serial_number', $imei)
                  ->orWhere('extra_serial_number', $imei);
            })
            ->first();

        return response()->json(['data' => $inventory]);
    }

    public function scanBarcode(string $barcode, AccessoryService $accessoryService): JsonResponse
    {
        $shopId = auth()->user()->shop_id;

        if (!$shopId) {
            // If no shop assigned, try finding any active accessory
            $accessory = Accessory::with('product:id,name')
                ->where('barcode', $barcode)
                ->where('is_active', true)
                ->whereRaw('quantity - sold_quantity - consigned_quantity > 0')
                ->orderBy('created_at', 'asc')
                ->first();
        } else {
            $accessory = $accessoryService->findForSale($barcode, $shopId);
            $accessory?->load('product:id,name');
        }

        return response()->json(['data' => $accessory]);
    }
}
