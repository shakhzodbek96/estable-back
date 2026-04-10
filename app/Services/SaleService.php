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

            return SaleItem::create([
                'sale_id' => $sale->id,
                'item_type' => 'serial',
                'inventory_id' => $inventory->id,
                'quantity' => 1,
                'unit_price' => $item['unit_price'],
                'subtotal' => $item['unit_price'],
                'warranty_months' => $item['warranty_months'] ?? null,
                'warranty_note' => $item['warranty_note'] ?? null,
            ]);
        }

        // Bulk
        $accessory = Accessory::lockForUpdate()->findOrFail($item['accessory_id']);
        $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
        $quantity = $item['quantity'] ?? 1;

        if ($available < $quantity) {
            throw new \Exception("Yetarli emas. Mavjud: {$available}");
        }

        $accessory->increment('sold_quantity', $quantity);

        $fresh = $accessory->fresh();
        if ($fresh->quantity - $fresh->sold_quantity - $fresh->consigned_quantity == 0) {
            $fresh->update(['is_active' => false]);
        }

        return SaleItem::create([
            'sale_id' => $sale->id,
            'item_type' => 'bulk',
            'accessory_id' => $accessory->id,
            'quantity' => $quantity,
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['unit_price'] * $quantity,
            'warranty_months' => $item['warranty_months'] ?? null,
            'warranty_note' => $item['warranty_note'] ?? null,
        ]);
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
        $investorIds = collect($items)->map(function ($item) {
            if ($item['item_type'] === 'serial') {
                return Inventory::find($item['inventory_id'])?->investor_id;
            }
            return Accessory::find($item['accessory_id'])?->investor_id;
        })->unique()->filter();

        return $investorIds->count() === 1 ? $investorIds->first() : null;
    }
}
