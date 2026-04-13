<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\SalePaymentStatus;
use App\Models\Accessory;
use App\Models\Inventory;
use App\Models\Rate;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function create(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            $sale = Sale::create([
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'total_price' => $this->calculateTotal($data['items']),
                'payment_method' => $this->determinePaymentMethod($data['payments']),
                'investor_id' => $this->determineInvestor($data['items']),
                'shop_id' => auth()->user()->shop_id,
                'sold_by' => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                $this->createSaleItem($sale, $item);
            }

            foreach ($data['payments'] as $payment) {
                $this->createSalePayment($sale, $payment);
            }

            return $sale->load(['items.inventory.product', 'items.accessory.product', 'payments', 'customer']);
        });
    }

    private function createSaleItem(Sale $sale, array $item): SaleItem
    {
        if ($item['item_type'] === 'serial') {
            $inventory = Inventory::lockForUpdate()->findOrFail($item['inventory_id']);

            if ($inventory->status !== InventoryStatus::InStock) {
                throw new \Exception("Tovar mavjud emas: {$inventory->serial_number}");
            }

            $inventory->update([
                'status' => InventoryStatus::Sold,
                'sold_price' => $item['unit_price'],
                'sold_at' => now(),
            ]);

            $saleItem = SaleItem::create([
                'sale_id' => $sale->id,
                'item_type' => 'serial',
                'inventory_id' => $inventory->id,
                'quantity' => 1,
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['unit_price'],
                'warranty_months' => $item['warranty_months'] ?? null,
                'warranty_note' => $item['warranty_note'] ?? null,
            ]);

            // Konsignatsiya tovari bo'lsa partner balansini yangilash
            $saleItem->setRelation('inventory', $inventory);
            app(ConsignmentService::class)->handleIncomingItemSold($saleItem);

            return $saleItem;
        }

        // Bulk
        $accessory = Accessory::lockForUpdate()->findOrFail($item['accessory_id']);

        if (!$accessory->is_active) {
            throw new \Exception("Aksessuar partiyasi deaktivatsiya qilingan: {$accessory->barcode}");
        }

        $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
        $quantity = $item['quantity'] ?? 1;

        if ($available < $quantity) {
            throw new \Exception("Yetarli emas. Mavjud: {$available}");
        }

        $accessory->increment('sold_quantity', $quantity);

        // fresh() o'rniga increment natijasini hisobga olamiz
        $newAvailable = $accessory->quantity - ($accessory->sold_quantity + $quantity) - $accessory->consigned_quantity;
        if ($newAvailable <= 0) {
            $accessory->update(['is_active' => false]);
        }

        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'item_type' => 'bulk',
            'accessory_id' => $accessory->id,
            'quantity' => $quantity,
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['unit_price'] * $quantity,
            'warranty_months' => $item['warranty_months'] ?? null,
            'warranty_note' => $item['warranty_note'] ?? null,
        ]);

        // Konsignatsiya tovari bo'lsa partner balansini yangilash
        $saleItem->setRelation('accessory', $accessory);
        app(ConsignmentService::class)->handleIncomingItemSold($saleItem);

        return $saleItem;
    }

    private function createSalePayment(Sale $sale, array $payment): SalePayment
    {
        $rate = Rate::current();

        return SalePayment::create([
            'sale_id' => $sale->id,
            'shop_id' => $sale->shop_id,
            'amount' => $payment['amount'],
            'type' => $payment['type'],
            'rate' => $payment['rate'] ?? $rate?->rate ?? 0,
            'currency' => $payment['currency'] ?? 'usd',
            'investor_id' => $sale->investor_id,
            'status' => SalePaymentStatus::New,
            'created_by' => auth()->id(),
            'details' => $payment['details'] ?? [],
        ]);
    }

    private function calculateTotal(array $items): float
    {
        return collect($items)->sum(function ($item) {
            return $item['unit_price'] * ($item['quantity'] ?? 1);
        });
    }

    private function determinePaymentMethod(array $payments): string
    {
        if (count($payments) > 1) return 'multiple';
        return $payments[0]['type'];
    }

    private function determineInvestor(array $items): ?int
    {
        $collection = collect($items);

        // Batch load — N query o'rniga 2 ta query
        $serialIds = $collection->where('item_type', 'serial')->pluck('inventory_id')->filter()->values();
        $bulkIds = $collection->where('item_type', 'bulk')->pluck('accessory_id')->filter()->values();

        $investorIds = collect();

        if ($serialIds->isNotEmpty()) {
            $investorIds = $investorIds->merge(
                Inventory::whereIn('id', $serialIds)->whereNotNull('investor_id')->pluck('investor_id')
            );
        }
        if ($bulkIds->isNotEmpty()) {
            $investorIds = $investorIds->merge(
                Accessory::whereIn('id', $bulkIds)->whereNotNull('investor_id')->pluck('investor_id')
            );
        }

        $unique = $investorIds->unique()->filter();
        return $unique->count() === 1 ? $unique->first() : null;
    }
}
