<?php

namespace App\Http\Controllers\Api;

use App\Enums\InventoryStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Inventory;
use App\Models\User;
use App\Services\AccessoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleScanController extends Controller
{
    /**
     * Admin rolidagi foydalanuvchi barcha magazinlardan sotuv qilish huquqiga ega
     * (shop_id filtri qo'llanilmaydi). Manager va Seller — faqat o'z magazini.
     */
    private function shopFilter(): ?int
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->role === UserRole::Admin) {
            return null; // admin → cheklovsiz
        }

        return $user->shop_id;
    }

    public function scanImei(string $imei): JsonResponse
    {
        $shopId = $this->shopFilter();

        $query = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name'])
            ->where('status', InventoryStatus::InStock)
            ->where(function ($q) use ($imei) {
                $q->where('serial_number', $imei)
                  ->orWhere('extra_serial_number', $imei);
            });

        if ($shopId) {
            $query->where('shop_id', $shopId);
        }

        return response()->json(['data' => $query->first()]);
    }

    public function scanBarcode(string $barcode, AccessoryService $accessoryService): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $shopId = $this->shopFilter();

        // Admin: barcode bo'yicha qidirish — birinchi mavjud partiyani qaytaradi
        if ($user->role === UserRole::Admin) {
            $accessory = Accessory::with('product:id,name')
                ->where('barcode', $barcode)
                ->where('is_active', true)
                ->whereRaw('quantity - sold_quantity - consigned_quantity > 0')
                ->orderBy('created_at', 'asc')
                ->first();

            return response()->json(['data' => $accessory]);
        }

        if (!$shopId) {
            return response()->json(['message' => 'Foydalanuvchiga do\'kon biriktirilmagan'], 422);
        }

        $accessory = $accessoryService->findForSale($barcode, $shopId);
        $accessory?->load('product:id,name');

        return response()->json(['data' => $accessory]);
    }

    /**
     * Nom bo'yicha qidirish — serial (in_stock inventories) va bulk (active accessories) alohida.
     * Admin rolidagi user — barcha magazinlar, boshqa rollar — faqat o'z magazini.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $search = $request->string('q')->trim()->value();
        $shopId = $this->shopFilter();

        if (!$search || strlen($search) < 2) {
            return response()->json(['serial' => [], 'bulk' => []]);
        }

        // Serial: in_stock inventories — tovar nomi yoki IMEI bo'yicha
        $serialQuery = Inventory::with(['product:id,name,type', 'shop:id,name', 'investor:id,name'])
            ->where('status', InventoryStatus::InStock)
            ->where(function ($q) use ($search) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'ilike', "%{$search}%"))
                  ->orWhere('serial_number', 'ilike', "%{$search}%")
                  ->orWhere('extra_serial_number', 'ilike', "%{$search}%");
            });

        if ($shopId) {
            $serialQuery->where('shop_id', $shopId);
        }

        $serials = $serialQuery
            ->select('id', 'product_id', 'serial_number', 'extra_serial_number', 'selling_price', 'wholesale_price', 'status', 'has_box', 'state', 'notes', 'shop_id', 'investor_id')
            ->limit(15)
            ->get();

        // Bulk: active accessories — tovar nomi yoki barcode bo'yicha
        $bulkQuery = Accessory::with(['product:id,name,type', 'shop:id,name'])
            ->where('is_active', true)
            ->whereRaw('quantity - sold_quantity - consigned_quantity > 0')
            ->where(function ($q) use ($search) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'ilike', "%{$search}%"))
                  ->orWhere('barcode', 'ilike', "%{$search}%");
            });

        if ($shopId) {
            $bulkQuery->where('shop_id', $shopId);
        }

        $bulks = $bulkQuery
            ->select('id', 'product_id', 'barcode', 'quantity', 'sold_quantity', 'consigned_quantity', 'sell_price', 'wholesale_price', 'invoice_number', 'notes', 'shop_id')
            ->orderBy('created_at', 'asc')
            ->limit(15)
            ->get();

        return response()->json([
            'serial' => $serials,
            'bulk' => $bulks,
        ]);
    }
}
