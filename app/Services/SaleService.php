<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\SalePaymentStatus;
use App\Models\Accessory;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Rate;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class SaleService
{
    /** Mijoz optovikmi (narx chegarasini kontekstli hisoblash uchun) */
    private bool $isWholesale = false;

    /** POS skidka limitlari (foizda): ['serial' => ?, 'accessory' => ?] */
    private array $discountLimits = ['serial' => null, 'accessory' => null];

    public function create(array $data): Sale
    {
        return DB::transaction(function () use ($data) {
            // Narx chegarasi konteksti — mijoz turi va limit konfiguratsiyasi
            $this->isWholesale = ! empty($data['customer_id'])
                && (bool) (Customer::find($data['customer_id'])?->is_wholesale);
            $this->discountLimits = $this->loadDiscountLimits();

            // Server tomonda: to'lovlar yig'indisi tovarlar summasiga teng bo'lishi shart
            // (UI buni majburlaydi; bu — defense-in-depth, to'g'ridan-to'g'ri/buggy API chaqiruvlariga qarshi).
            $total = $this->calculateTotal($data['items']);
            $this->assertPaymentsBalance($data['payments'], $total);

            // Smena majburiy — ochiq smena bo'lmasa savdo qilib bo'lmaydi.
            // Aks holda yig'ilган naqд hech qaysi smena сверка'siga kirmaydi.
            $shopId = $this->determineShop($data['items']);
            if (! \App\Models\CashShift::openForShop($shopId)) {
                throw new \Exception('Откройте смену перед продажей');
            }

            $sale = Sale::create([
                'customer_id' => $data['customer_id'] ?? null,
                'sale_date' => $data['sale_date'] ?? now()->toDateString(),
                'total_price' => $total,
                'payment_method' => $this->determinePaymentMethod($data['payments']),
                'investor_id' => $this->determineInvestor($data['items']),
                'shop_id' => $shopId,
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
                throw new \Exception("Товар недоступен для продажи: {$inventory->serial_number}");
            }

            $basePrice = $this->isWholesale && $inventory->wholesale_price !== null
                ? (float) $inventory->wholesale_price
                : (float) $inventory->selling_price;
            $this->assertPriceWithinLimit('serial', $basePrice, (float) $item['unit_price']);

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
            throw new \Exception("Партия аксессуара неактивна (нет в наличии): {$accessory->barcode}");
        }

        $basePrice = $this->isWholesale && $accessory->wholesale_price !== null
            ? (float) $accessory->wholesale_price
            : (float) $accessory->sell_price;
        $this->assertPriceWithinLimit('accessory', $basePrice, (float) $item['unit_price']);

        $available = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
        $quantity = $item['quantity'] ?? 1;

        if ($available < $quantity) {
            throw new \Exception("Недостаточно товара «{$accessory->barcode}». В наличии: {$available}, запрошено: {$quantity}");
        }

        $accessory->increment('sold_quantity', $quantity);

        // increment() xotiradagi sold_quantity'ni allaqachon yangiladi — qayta qo'shmaymiz
        $newAvailable = $accessory->quantity - $accessory->sold_quantity - $accessory->consigned_quantity;
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
            'shift_id' => \App\Models\CashShift::openForShop($sale->shop_id)?->id,
            'amount' => $payment['amount'],
            'type' => $payment['type'],
            'rate' => $payment['rate'] ?? $rate?->rate ?? 0,
            'currency' => $payment['currency'] ?? 'usd',
            'investor_id' => $sale->investor_id,
            'status' => SalePaymentStatus::New,
            'created_by' => auth()->id(),
            'comment' => $payment['comment'] ?? null,
            'details' => $payment['details'] ?? [],
        ]);
    }

    /**
     * Sotuvchi belgilangan skidka limitidan oshib ketmaganini tekshiradi.
     * Limit null bo'lsa — cheklov yo'q. Narxni yuqoriga ko'tarish doim mumkin.
     *
     * @param 'serial'|'accessory' $type
     */
    private function assertPriceWithinLimit(string $type, float $basePrice, float $unitPrice): void
    {
        $limit = $this->discountLimits[$type] ?? null;
        if ($limit === null || $basePrice <= 0) {
            return;
        }

        $floor = $basePrice * (1 - $limit / 100);

        // 1 sent tolerantlik (suzuvchi nuqta / yaxlitlash uchun)
        if ($unitPrice < $floor - 0.01) {
            $label = $type === 'serial' ? 'товара' : 'аксессуара';
            throw new \Exception(sprintf(
                'Цена %s ниже минимально допустимой. Скидка не более %s%%, минимум $%s.',
                $label,
                rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.'),
                number_format($floor, 2, '.', ''),
            ));
        }
    }

    /**
     * @return array{serial: float|null, accessory: float|null}
     */
    private function loadDiscountLimits(): array
    {
        $payload = Setting::getValue(Setting::POS_DISCOUNT_LIMITS, []);
        $payload = is_array($payload) ? $payload : [];

        $clamp = static function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            return max(0.0, min(100.0, (float) $v));
        };

        return [
            'serial' => $clamp($payload['serial'] ?? null),
            'accessory' => $clamp($payload['accessory'] ?? null),
        ];
    }

    private function calculateTotal(array $items): float
    {
        return collect($items)->sum(function ($item) {
            return $item['unit_price'] * ($item['quantity'] ?? 1);
        });
    }

    /**
     * To'lovlar yig'indisi (USD'ga keltirilgan) tovarlar summasiga tengligini tekshiradi.
     * Bu tizimda qisman/qarz sotuv yo'q — har sotuv to'liq to'lanadi (to'lovlar keyin tasdiqlanadi).
     */
    private function assertPaymentsBalance(array $payments, float $total): void
    {
        $rate = Rate::current();

        $paid = collect($payments)->sum(function ($p) use ($rate) {
            $amount = (float) ($p['amount'] ?? 0);
            $currency = $p['currency'] ?? 'usd';
            if ($currency === 'usd') {
                return $amount;
            }
            $r = (float) ($p['rate'] ?? $rate?->rate ?? 0);
            return $r > 0 ? $amount / $r : 0.0;
        });

        if (abs($paid - $total) > 0.01) {
            throw new \Exception(sprintf(
                'Сумма платежей ($%s) не совпадает с суммой товаров ($%s).',
                number_format($paid, 2, '.', ''),
                number_format($total, 2, '.', '')
            ));
        }
    }

    private function determinePaymentMethod(array $payments): string
    {
        if (count($payments) > 1) return 'multiple';
        return $payments[0]['type'];
    }

    /**
     * Sotuvning do'konini tovarlardan olamiz (operator emas!).
     * Tovar qaysi do'konda turgan bo'lsa — sotuv o'sha do'koniki.
     * Agar savatda turli do'kon tovarlari bo'lsa — xato.
     */
    private function determineShop(array $items): int
    {
        $collection = collect($items);
        $serialIds = $collection->where('item_type', 'serial')->pluck('inventory_id')->filter()->values();
        $bulkIds = $collection->where('item_type', 'bulk')->pluck('accessory_id')->filter()->values();

        $shopIds = collect();
        if ($serialIds->isNotEmpty()) {
            $shopIds = $shopIds->merge(
                Inventory::whereIn('id', $serialIds)->pluck('shop_id')
            );
        }
        if ($bulkIds->isNotEmpty()) {
            $shopIds = $shopIds->merge(
                Accessory::whereIn('id', $bulkIds)->pluck('shop_id')
            );
        }

        $unique = $shopIds->unique()->filter()->values();
        if ($unique->isEmpty()) {
            throw new \Exception('Не удалось определить магазин товара');
        }
        if ($unique->count() > 1) {
            throw new \Exception('В корзине товары из разных магазинов — продать одновременно нельзя');
        }

        return (int) $unique->first();
    }

    /**
     * Sotuvning egasini (investor yoki do'kon) aniqlaydi — DENORMALIZATSIYA uchun.
     *
     * Bir savatda turli egalar (bir necha investor yoki investor+do'kon) tovarlari bo'lishi
     * MUMKIN (bitta do'kon doirasida — determineShop cheklaydi). Bunday aralash sotuvda
     * `sales.investor_id` = null bo'ladi; haqiqiy hisob-kitob (mablag' qaytishi + foyda)
     * item darajasida, har egа ulushiga qarab taqsimlanadi
     * (SalePaymentService::acceptPaymentRecord). Bu ustun endi faqat yagona-investor
     * sotuvlar uchun ma'lumot/qulaylik sifatida saqlanadi.
     */
    private function determineInvestor(array $items): ?int
    {
        $collection = collect($items);

        // Batch load — N query o'rniga 2 ta query
        $serialIds = $collection->where('item_type', 'serial')->pluck('inventory_id')->filter()->values();
        $bulkIds = $collection->where('item_type', 'bulk')->pluck('accessory_id')->filter()->values();

        $owners = collect();

        if ($serialIds->isNotEmpty()) {
            $owners = $owners->merge(Inventory::whereIn('id', $serialIds)->pluck('investor_id'));
        }
        if ($bulkIds->isNotEmpty()) {
            $owners = $owners->merge(Accessory::whereIn('id', $bulkIds)->pluck('investor_id'));
        }

        // null (do'kon mablag'i) ni alohida ega sifatida qaraymiz
        $distinct = $owners->map(fn ($v) => $v === null ? 'shop' : (int) $v)->unique()->values();

        // Aralash yoki do'kon egaligi → null. Faqat yagona investor bo'lsa uni yozamiz.
        if ($distinct->count() !== 1) {
            return null;
        }

        $only = $distinct->first();
        return $only === 'shop' ? null : (int) $only;
    }
}
