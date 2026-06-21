<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\SalePaymentStatus;
use App\Models\Accessory;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Transaction;
use App\Services\SalePaymentService;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * 1. Dashboard — asosiy ko'rsatkichlar
     */
    public function dashboard(array $filters): array
    {
        // Standart davr — oxirgi 30 kun (bugun ham kiradi)
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $dateFrom = $filters['date_from'] ?? now()->subDays(29)->toDateString();
        $shopId = $filters['shop_id'] ?? null;
        $days = max(1, \Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo)) + 1);

        // ---- Sotuv (joriy davr) ----
        $cur = $this->salesAggregate($dateFrom, $dateTo, $shopId);
        $saleIds = $cur['ids'];

        // ---- Oldingi teng davr (taqqoslash) ----
        $prevTo = \Carbon\Carbon::parse($dateFrom)->subDay()->toDateString();
        $prevFrom = \Carbon\Carbon::parse($prevTo)->subDays($days - 1)->toDateString();
        $prev = $this->salesAggregate($prevFrom, $prevTo, $shopId);

        $pct = static fn(float $now, float $old): ?float =>
            $old > 0 ? round(($now - $old) / $old * 100, 1) : ($now > 0 ? null : 0.0);

        // ---- Bugungi savdo ----
        $today = $this->salesAggregate($dateTo, $dateTo, $shopId);

        // ---- To'lov turlari bo'yicha (pie) ----
        $byPaymentMethod = $saleIds->isEmpty() ? collect() : SalePayment::query()
            ->where('status', SalePaymentStatus::Accepted)
            ->whereIn('sale_id', $saleIds)
            ->selectRaw("type, SUM(CASE WHEN currency = 'usd' THEN amount ELSE amount / NULLIF(rate, 0) END) as total_usd")
            ->groupBy('type')
            ->pluck('total_usd', 'type')
            ->map(fn($v) => round((float) $v, 2));

        // ---- Harajatlar (operatsion; purchase/repair — COGS, chiqariladi) ----
        $totalExpenses = (float) Transaction::query()
            ->where('is_credit', false)
            ->whereNotIn('type', ['purchase', 'repair'])
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->sum('amount');

        // ---- Sklad qoldig'i (hozirgi holat) ----
        $serialQuery = Inventory::query()->where('status', InventoryStatus::InStock)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));
        // count + value — bitta query (avval ikkita alohida edi)
        $serialAgg = (clone $serialQuery)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(purchase_price + extra_cost), 0) as total')
            ->first();
        $serialCount = (int) $serialAgg->cnt;
        $serialValue = (float) $serialAgg->total;

        $accessoryQuery = Accessory::query()->where('is_active', true)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));
        // count + value — bitta query
        $accAgg = (clone $accessoryQuery)
            ->selectRaw('COALESCE(SUM(quantity - sold_quantity - consigned_quantity), 0) as cnt, COALESCE(SUM(purchase_price * (quantity - sold_quantity - consigned_quantity)), 0) as total')
            ->first();
        $accessoriesCount = (int) $accAgg->cnt;
        $accessoriesValue = (float) $accAgg->total;

        // ---- Sotib olingan tovar (davr; created_at bo'yicha) ----
        $window = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
        $purSerialQ = Inventory::query()->whereBetween('created_at', $window)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));
        $purSerialAgg = (clone $purSerialQ)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(purchase_price + extra_cost), 0) as total')
            ->first();
        $purSerialCount = (int) $purSerialAgg->cnt;
        $purSerialSum = (float) $purSerialAgg->total;

        $purAccQ = Accessory::query()->whereBetween('created_at', $window)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));
        $purAccAgg = (clone $purAccQ)
            ->selectRaw('COALESCE(SUM(quantity), 0) as cnt, COALESCE(SUM(purchase_price * quantity), 0) as total')
            ->first();
        $purAccCount = (int) $purAccAgg->cnt;
        $purAccSum = (float) $purAccAgg->total;

        // ---- Eng foydali tovarlar (foyda bo'yicha top 5) ----
        $topProfit = $saleIds->isEmpty() ? collect() : $this->topProductsQuery($saleIds, 'serial', 10)->get()
            ->concat($this->topProductsQuery($saleIds, 'bulk', 10)->get())
            ->sortByDesc(fn($r) => (float) $r->total_profit)
            ->take(5)
            ->values();

        // ---- Kam qolgan tovarlar (mahsulot min_stock chegarasi bo'yicha) ----
        // Aksessuar: barcha partiyalar qoldig'i yig'indisi; serial: in_stock dona soni.
        $lowAcc = Accessory::query()
            ->where('accessories.is_active', true)
            ->when($shopId, fn($q, $id) => $q->where('accessories.shop_id', $id))
            ->join('products', 'accessories.product_id', '=', 'products.id')
            ->whereNotNull('products.min_stock')->where('products.min_stock', '>', 0)
            ->groupBy('products.id', 'products.name', 'products.min_stock')
            ->havingRaw('SUM(accessories.quantity - accessories.sold_quantity - accessories.consigned_quantity) <= products.min_stock')
            ->selectRaw("products.name as product_name, 'bulk' as type, SUM(accessories.quantity - accessories.sold_quantity - accessories.consigned_quantity) as available, products.min_stock as min_stock")
            ->get();

        $lowSer = Inventory::query()
            ->where('inventories.status', InventoryStatus::InStock)
            ->when($shopId, fn($q, $id) => $q->where('inventories.shop_id', $id))
            ->join('products', 'inventories.product_id', '=', 'products.id')
            ->whereNotNull('products.min_stock')->where('products.min_stock', '>', 0)
            ->groupBy('products.id', 'products.name', 'products.min_stock')
            ->havingRaw('COUNT(*) <= products.min_stock')
            ->selectRaw("products.name as product_name, 'serial' as type, COUNT(*) as available, products.min_stock as min_stock")
            ->get();

        $lowStock = $lowSer->concat($lowAcc)
            ->sortBy(fn($r) => (int) $r->available)
            ->take(10)
            ->values();

        // ---- Uzoq turib qolgan serial tovarlar (dead stock) ----
        // 30/60/90 kun bucket'lari — bitta query (Postgres FILTER; avval 3×2=6 query edi)
        $deadAgg = Inventory::query()->where('status', InventoryStatus::InStock)
            ->when($shopId, fn($qq, $id) => $qq->where('shop_id', $id))
            ->selectRaw(
                'COUNT(*) FILTER (WHERE created_at <= ?) as c30, '
                . 'COALESCE(SUM(purchase_price + extra_cost) FILTER (WHERE created_at <= ?), 0) as v30, '
                . 'COUNT(*) FILTER (WHERE created_at <= ?) as c60, '
                . 'COALESCE(SUM(purchase_price + extra_cost) FILTER (WHERE created_at <= ?), 0) as v60, '
                . 'COUNT(*) FILTER (WHERE created_at <= ?) as c90, '
                . 'COALESCE(SUM(purchase_price + extra_cost) FILTER (WHERE created_at <= ?), 0) as v90',
                [
                    now()->subDays(30), now()->subDays(30),
                    now()->subDays(60), now()->subDays(60),
                    now()->subDays(90), now()->subDays(90),
                ]
            )
            ->first();
        $deadBucket = fn (string $c, string $v) => [
            'count' => (int) $deadAgg->{$c},
            'value' => round((float) $deadAgg->{$v}, 2),
        ];
        $deadOldest = Inventory::query()->where('inventories.status', InventoryStatus::InStock)
            ->where('inventories.created_at', '<=', now()->subDays(30))
            ->when($shopId, fn($q, $id) => $q->where('inventories.shop_id', $id))
            ->join('products', 'inventories.product_id', '=', 'products.id')
            ->selectRaw('products.name as product_name, inventories.serial_number, inventories.created_at, (inventories.purchase_price + inventories.extra_cost) as cost')
            ->orderBy('inventories.created_at', 'asc')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'product_name' => $r->product_name,
                'serial_number' => $r->serial_number,
                'days' => (int) \Carbon\Carbon::parse($r->created_at)->diffInDays(now()),
                'cost' => round((float) $r->cost, 2),
            ]);

        // ---- Kassa (davr; tasdiqlangan to'lovlar, usul × valyuta) ----
        $kassa = app(SalePaymentService::class)->getKassaSummary([
            'shop_id' => $shopId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        // ---- Pul oqimi (davr; tranzaksiya ledjeri) ----
        $inflow = (float) Transaction::query()->where('is_credit', true)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))->sum('amount');
        $outflow = (float) Transaction::query()->where('is_credit', false)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))->sum('amount');

        // ---- Tasdiqlanmagan to'lovlar (hozirgi) ----
        $pendingPayments = (float) SalePayment::query()
            ->where('status', SalePaymentStatus::New)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("SUM(CASE WHEN currency = 'usd' THEN amount ELSE amount / NULLIF(rate, 0) END) as total_usd")
            ->value('total_usd') ?: 0;

        // ---- Investorlar xulosasi (hozirgi holat) ----
        $invBalance = (float) Investor::sum('balance');
        $invInGoodsSerial = (float) Inventory::query()->whereNotNull('investor_id')
            ->where('status', InventoryStatus::InStock)
            ->selectRaw('SUM(purchase_price + extra_cost) as total')->value('total') ?: 0;
        $invInGoodsAcc = (float) Accessory::query()->whereNotNull('investor_id')
            ->where('is_active', true)
            ->selectRaw('SUM(purchase_price * (quantity - sold_quantity - consigned_quantity)) as total')->value('total') ?: 0;
        $invInGoods = $invInGoodsSerial + $invInGoodsAcc;
        $invInvested = (float) Investment::where('type', 1)->where('is_credit', true)->sum('amount');

        // ---- Yangi mijozlar (davr) ----
        $newCustomers = (int) Customer::whereBetween('created_at', $window)->count();

        // ---- Oxirgi 10 sotuv ----
        $recentSales = Sale::with(['customer:id,name', 'seller:id,name'])
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'customer_id', 'sold_by', 'sale_date', 'total_price', 'payment_method']);

        // ---- Kunlik sotuvlar (grafik) ----
        $dailySales = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("sale_date as date, COUNT(*) as count, SUM(total_price) as revenue")
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();

        return [
            'period' => ['from' => $dateFrom, 'to' => $dateTo, 'days' => $days],
            'sales' => [
                'count' => $cur['count'],
                'total_revenue' => $cur['revenue'],
                'total_cost' => $cur['cost'],
                'gross_profit' => $cur['gross_profit'],
                'avg_daily_revenue' => round($cur['revenue'] / $days, 2),
                'avg_daily_profit' => round($cur['gross_profit'] / $days, 2),
                'by_payment_method' => $byPaymentMethod,
            ],
            'comparison' => [
                'revenue_prev' => $prev['revenue'],
                'revenue_pct' => $pct($cur['revenue'], $prev['revenue']),
                'profit_prev' => $prev['gross_profit'],
                'profit_pct' => $pct($cur['gross_profit'], $prev['gross_profit']),
            ],
            'today' => [
                'count' => $today['count'],
                'revenue' => $today['revenue'],
                'profit' => $today['gross_profit'],
            ],
            'expenses' => ['total' => round($totalExpenses, 2)],
            'net_profit' => round($cur['gross_profit'] - $totalExpenses, 2),
            'inventory' => [
                'serial_count' => $serialCount,
                'serial_value' => round($serialValue, 2),
                'accessories_count' => $accessoriesCount,
                'accessories_value' => round($accessoriesValue, 2),
                'total_value' => round($serialValue + $accessoriesValue, 2),
            ],
            'purchases' => [
                'serial_count' => $purSerialCount,
                'serial_sum' => round($purSerialSum, 2),
                'accessory_count' => $purAccCount,
                'accessory_sum' => round($purAccSum, 2),
                'total_sum' => round($purSerialSum + $purAccSum, 2),
            ],
            'top_profit' => $topProfit,
            'low_stock' => $lowStock,
            'dead_stock' => [
                'over_30' => $deadBucket('c30', 'v30'),
                'over_60' => $deadBucket('c60', 'v60'),
                'over_90' => $deadBucket('c90', 'v90'),
                'oldest' => $deadOldest,
            ],
            'cash' => $kassa['accepted'],
            'cash_flow' => [
                'inflow' => round($inflow, 2),
                'outflow' => round($outflow, 2),
                'net' => round($inflow - $outflow, 2),
            ],
            'pending_payments' => round($pendingPayments, 2),
            'investors' => [
                'invested' => round($invInvested, 2),
                'in_goods' => round($invInGoods, 2),
                'accumulated_profit' => round($invBalance + $invInGoods - $invInvested, 2),
                'balance' => round($invBalance, 2),
            ],
            'new_customers' => $newCustomers,
            'recent_sales' => $recentSales,
            'daily_sales' => $dailySales,
        ];
    }

    /**
     * Davr bo'yicha tasdiqlangan sotuv yig'masi: count, revenue, cost, gross_profit, ids.
     */
    private function salesAggregate(string $from, string $to, ?int $shopId): array
    {
        $q = Sale::query()
            ->whereBetween('sale_date', [$from, $to])
            ->whereHas('payments', fn($p) => $p->where('status', SalePaymentStatus::Accepted))
            ->when($shopId, fn($qq, $id) => $qq->where('shop_id', $id));

        // count + sum + ids — bitta query bilan (avval 3 ta alohida query edi,
        // har biri qimmat whereHas('payments') subquery'sini takrorlardi).
        $rows = (clone $q)->get(['id', 'total_price']);
        $count = $rows->count();
        $revenue = (float) $rows->sum(fn ($r) => (float) $r->total_price);
        $ids = $rows->pluck('id');
        $cost = $this->calculateCost($ids);

        return [
            'count' => $count,
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'gross_profit' => round($revenue - $cost, 2),
            'ids' => $ids,
        ];
    }

    /**
     * 2. Foyda hisoboti
     */
    public function profit(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $shopId = $filters['shop_id'] ?? null;
        $groupBy = $filters['group_by'] ?? 'day';

        $dateExpr = match ($groupBy) {
            'week' => "DATE_TRUNC('week', sale_date)::date",
            'month' => "DATE_TRUNC('month', sale_date)::date",
            default => 'sale_date',
        };

        // Davr bo'yicha sotuvlar
        $salesData = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("{$dateExpr} as period, COUNT(*) as count, SUM(total_price) as revenue")
            ->groupByRaw($dateExpr)
            ->orderByRaw("{$dateExpr}")
            ->get();

        // Barcha sale ID'larni bir marta olish (period bilan)
        $allSales = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("id, {$dateExpr} as period")
            ->get();

        $salesByPeriod = $allSales->groupBy('period');

        // Barcha tannarxni bir marta hisoblash (2 ta query)
        $allSaleIds = $allSales->pluck('id');
        $serialCosts = $allSaleIds->isNotEmpty()
            ? SaleItem::whereIn('sale_id', $allSaleIds)->where('item_type', 'serial')
                ->join('inventories', 'sale_items.inventory_id', '=', 'inventories.id')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->selectRaw("{$dateExpr} as period, SUM(inventories.purchase_price + inventories.extra_cost) as cost")
                ->groupByRaw($dateExpr)
                ->pluck('cost', 'period')
            : collect();

        $bulkCosts = $allSaleIds->isNotEmpty()
            ? SaleItem::whereIn('sale_id', $allSaleIds)->where('item_type', 'bulk')
                ->join('accessories', 'sale_items.accessory_id', '=', 'accessories.id')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->selectRaw("{$dateExpr} as period, SUM(accessories.purchase_price * sale_items.quantity) as cost")
                ->groupByRaw($dateExpr)
                ->pluck('cost', 'period')
            : collect();

        $periods = [];
        foreach ($salesData as $row) {
            $revenue = (float) $row->revenue;
            $cost = (float) ($serialCosts[$row->period] ?? 0) + (float) ($bulkCosts[$row->period] ?? 0);

            $periods[] = [
                'period' => $row->period,
                'count' => $row->count,
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'gross_profit' => round($revenue - $cost, 2),
            ];
        }

        // Harajatlar davr bo'yicha — purchase/repair CHIQARILADI (COGS, net_profit'da ikki marta sanalmasin)
        // $dateExpr sale_date ustuni asosida qurilgan; transactions jadvalida ustun nomi transaction_date,
        // shuning uchun select/group/order — barchasida almashtiramiz (faqat group/order'da emas).
        $txnDateExpr = str_replace('sale_date', 'transaction_date', $dateExpr);
        $expenses = Transaction::query()
            ->where('is_credit', false)
            ->whereNotIn('type', ['purchase', 'repair'])
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("{$txnDateExpr} as period, SUM(amount) as total")
            ->groupByRaw($txnDateExpr)
            ->orderByRaw($txnDateExpr)
            ->pluck('total', 'period')
            ->map(fn($v) => round((float) $v, 2));

        return [
            'group_by' => $groupBy,
            'periods' => $periods,
            'expenses_by_period' => $expenses,
        ];
    }

    /**
     * 3. Inventar qoldiqlari
     */
    public function inventory(array $filters): array
    {
        $shopId = $filters['shop_id'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $type = $filters['type'] ?? null;

        $serial = [];
        $bulk = [];

        if (!$type || $type === 'serial') {
            $serial = Inventory::query()
                ->where('status', InventoryStatus::InStock)
                ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
                ->when($categoryId, fn($q, $id) => $q->whereHas('product', fn($pq) => $pq->where('category_id', $id)))
                ->join('products', 'inventories.product_id', '=', 'products.id')
                ->selectRaw("products.id as product_id, products.name as product_name, COUNT(*) as count, AVG(purchase_price + extra_cost) as avg_cost, SUM(purchase_price + extra_cost) as total_value")
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('count')
                ->get()
                ->map(fn($r) => [
                    'product_id' => $r->product_id,
                    'product_name' => $r->product_name,
                    'count' => $r->count,
                    'avg_cost' => round((float) $r->avg_cost, 2),
                    'total_value' => round((float) $r->total_value, 2),
                ]);
        }

        if (!$type || $type === 'bulk') {
            $bulk = Accessory::query()
                ->where('is_active', true)
                ->whereRaw('quantity - sold_quantity - consigned_quantity > 0')
                ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
                ->when($categoryId, fn($q, $id) => $q->whereHas('product', fn($pq) => $pq->where('category_id', $id)))
                ->join('products', 'accessories.product_id', '=', 'products.id')
                ->selectRaw("products.id as product_id, products.name as product_name, accessories.barcode, SUM(accessories.quantity - accessories.sold_quantity - accessories.consigned_quantity) as available, SUM(accessories.purchase_price * (accessories.quantity - accessories.sold_quantity - accessories.consigned_quantity)) as total_value")
                ->groupBy('products.id', 'products.name', 'accessories.barcode')
                ->orderByDesc('available')
                ->get()
                ->map(fn($r) => [
                    'product_id' => $r->product_id,
                    'product_name' => $r->product_name,
                    'barcode' => $r->barcode,
                    'available' => (int) $r->available,
                    'total_value' => round((float) $r->total_value, 2),
                ]);
        }

        return [
            'serial' => $serial,
            'bulk' => $bulk,
        ];
    }

    /**
     * 4. Top tovarlar
     */
    public function topProducts(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $limit = $filters['limit'] ?? 20;
        $type = $filters['type'] ?? null;

        $saleIds = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->pluck('id');

        $serial = [];
        $bulk = [];

        if (!$type || $type === 'serial') {
            $serial = $this->topProductsQuery($saleIds, 'serial', $limit)->get()->toArray();
        }

        if (!$type || $type === 'bulk') {
            $bulk = $this->topProductsQuery($saleIds, 'bulk', $limit)->get()->toArray();
        }

        return ['serial' => $serial, 'bulk' => $bulk];
    }

    /**
     * 5. Sotuvchilar hisoboti
     */
    public function sellers(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $shopId = $filters['shop_id'] ?? null;

        $sellers = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->join('users', 'sales.sold_by', '=', 'users.id')
            ->selectRaw("users.id as seller_id, users.name as seller_name, COUNT(sales.id) as sales_count, SUM(sales.total_price) as total_revenue")
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get();

        // Barcha sale ID'larni oldindan olish (sotuvchi bo'yicha)
        $allSales = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->select('id', 'sold_by')
            ->get();

        $salesBySeller = $allSales->groupBy('sold_by');
        $allSaleIds = $allSales->pluck('id');

        // Tannarx — sotuvchi bo'yicha (2 ta query)
        $serialCostBySeller = $allSaleIds->isNotEmpty()
            ? SaleItem::whereIn('sale_items.sale_id', $allSaleIds)->where('item_type', 'serial')
                ->join('inventories', 'sale_items.inventory_id', '=', 'inventories.id')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->selectRaw("sales.sold_by, SUM(inventories.purchase_price + inventories.extra_cost) as cost")
                ->groupBy('sales.sold_by')
                ->pluck('cost', 'sold_by')
            : collect();

        $bulkCostBySeller = $allSaleIds->isNotEmpty()
            ? SaleItem::whereIn('sale_items.sale_id', $allSaleIds)->where('item_type', 'bulk')
                ->join('accessories', 'sale_items.accessory_id', '=', 'accessories.id')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->selectRaw("sales.sold_by, SUM(accessories.purchase_price * sale_items.quantity) as cost")
                ->groupBy('sales.sold_by')
                ->pluck('cost', 'sold_by')
            : collect();

        // Payment stats — barcha sotuvchilar uchun 1 ta query
        $paymentStats = $allSaleIds->isNotEmpty()
            ? SalePayment::whereIn('sale_id', $allSaleIds)
                ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                ->selectRaw("sales.sold_by, sale_payments.status, COUNT(*) as count")
                ->groupBy('sales.sold_by', 'sale_payments.status')
                ->get()
                ->groupBy('sold_by')
            : collect();

        return $sellers->map(function ($row) use ($serialCostBySeller, $bulkCostBySeller, $paymentStats) {
            $revenue = (float) $row->total_revenue;
            $cost = (float) ($serialCostBySeller[$row->seller_id] ?? 0) + (float) ($bulkCostBySeller[$row->seller_id] ?? 0);
            $stats = $paymentStats[$row->seller_id] ?? collect();

            return [
                'seller_id' => $row->seller_id,
                'seller_name' => $row->seller_name,
                'sales_count' => $row->sales_count,
                'total_revenue' => round($revenue, 2),
                'total_cost' => round($cost, 2),
                'total_profit' => round($revenue - $cost, 2),
                'avg_sale' => $row->sales_count > 0 ? round($revenue / $row->sales_count, 2) : 0,
                'accepted' => (int) ($stats->where('status', SalePaymentStatus::Accepted->value)->sum('count')),
                'rejected' => (int) ($stats->where('status', SalePaymentStatus::Rejected->value)->sum('count')),
                'pending' => (int) ($stats->where('status', SalePaymentStatus::New->value)->sum('count')),
            ];
        })->values()->toArray();
    }

    /**
     * 6. Top mijozlar
     */
    public function topCustomers(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $limit = $filters['limit'] ?? 20;

        return Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->whereNotNull('customer_id')
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->selectRaw("customers.id as customer_id, customers.name as customer_name, customers.phone, COUNT(sales.id) as sales_count, SUM(sales.total_price) as total_spent, MAX(sales.sale_date) as last_purchase")
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get()
            ->map(fn($r) => [
                'customer_id' => $r->customer_id,
                'customer_name' => $r->customer_name,
                'phone' => $r->phone,
                'sales_count' => $r->sales_count,
                'total_spent' => round((float) $r->total_spent, 2),
                'last_purchase' => $r->last_purchase,
            ])
            ->toArray();
    }

    /**
     * 7. Investor hisoboti
     */
    public function investorReport(int $investorId, array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();

        $investor = Investor::findOrFail($investorId);

        // Davr investitsiyalari
        $investments = Investment::where('investor_id', $investorId)
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->selectRaw("type, is_credit, SUM(amount) as total")
            ->groupBy('type', 'is_credit')
            ->get();

        $investedAmount = $investments->where('type', 1)->where('is_credit', true)->sum('total');
        $dividends = $investments->where('type', 2)->where('is_credit', false)->sum('total');
        // type=3 (ClientsPayment): sotuv tushumi (is_credit=true) MINUS qaytarish/refund (is_credit=false)
        $salesReceived = $investments->where('type', 3)->where('is_credit', true)->sum('total')
            - $investments->where('type', 3)->where('is_credit', false)->sum('total');
        $buyingProduct = $investments->where('type', 4)->where('is_credit', false)->sum('total');

        // Investor tovarlaridan foyda
        $investorProfit = SaleItem::query()
            ->whereHas('sale.payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->whereHas('sale', fn($q) => $q->whereBetween('sale_date', [$dateFrom, $dateTo]))
            ->where(function ($q) use ($investorId) {
                $q->whereHas('inventory', fn($iq) => $iq->where('investor_id', $investorId))
                    ->orWhereHas('accessory', fn($aq) => $aq->where('investor_id', $investorId));
            })
            ->with(['inventory:id,purchase_price,extra_cost', 'accessory:id,purchase_price'])
            ->get()
            ->sum(function ($item) {
                if ($item->item_type->value === 'serial' && $item->inventory) {
                    return (float) $item->unit_price - (float) $item->inventory->purchase_price - (float) $item->inventory->extra_cost;
                }
                if ($item->accessory) {
                    return ((float) $item->unit_price - (float) $item->accessory->purchase_price) * $item->quantity;
                }
                return 0;
            });

        // Mavjud tovarlar
        $activeSerials = Inventory::where('investor_id', $investorId)
            ->where('status', InventoryStatus::InStock)
            ->with('product:id,name')
            ->get(['id', 'product_id', 'serial_number', 'purchase_price', 'extra_cost', 'selling_price']);

        $activeAccessories = Accessory::where('investor_id', $investorId)
            ->where('is_active', true)
            ->with('product:id,name')
            ->get(['id', 'product_id', 'barcode', 'quantity', 'sold_quantity', 'consigned_quantity', 'purchase_price', 'sell_price']);

        // Oxirgi transaksiyalar
        $recentTransactions = Transaction::where('investor_id', $investorId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'investor' => ['id' => $investor->id, 'name' => $investor->name, 'balance' => (float) $investor->balance],
            'period' => [
                'invested' => round((float) $investedAmount, 2),
                'dividends' => round((float) $dividends, 2),
                'sales_received' => round((float) $salesReceived, 2),
                'buying_product' => round((float) $buyingProduct, 2),
                'profit' => round($investorProfit, 2),
            ],
            'active_inventory' => [
                'serial' => $activeSerials,
                'accessories' => $activeAccessories,
            ],
            'recent_transactions' => $recentTransactions,
        ];
    }

    /**
     * 8. Harajatlar hisoboti
     */
    public function expenses(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $type = $filters['type'] ?? null;

        $query = Transaction::query()
            ->where('is_credit', false)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($type, fn($q, $t) => $q->where('type', $t));

        // Tur bo'yicha guruhlangan
        $byType = (clone $query)
            ->selectRaw("type, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'type' => $r->type,
                'count' => $r->count,
                'total' => round((float) $r->total, 2),
            ]);

        // Kunlik harajatlar (grafik)
        $daily = (clone $query)
            ->selectRaw("transaction_date as date, SUM(amount) as total")
            ->groupBy('transaction_date')
            ->orderBy('transaction_date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => round((float) $r->total, 2)]);

        $total = round((float) (clone $query)->sum('amount'), 2);

        return [
            'total' => $total,
            'by_type' => $byType,
            'daily' => $daily,
        ];
    }

    // ---- Yordamchi metodlar ----

    /**
     * Sotuvlar tannarxini hisoblash
     */
    private function calculateCost($saleIds): float
    {
        if ($saleIds->isEmpty()) return 0;

        $serialCost = (float) SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->where('item_type', 'serial')
            ->join('inventories', 'sale_items.inventory_id', '=', 'inventories.id')
            ->selectRaw('SUM(inventories.purchase_price + inventories.extra_cost) as total')
            ->value('total') ?: 0;

        $bulkCost = (float) SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->where('item_type', 'bulk')
            ->join('accessories', 'sale_items.accessory_id', '=', 'accessories.id')
            ->selectRaw('SUM(accessories.purchase_price * sale_items.quantity) as total')
            ->value('total') ?: 0;

        return $serialCost + $bulkCost;
    }

    /**
     * Top tovarlar so'rovi
     */
    private function topProductsQuery($saleIds, string $type, int $limit)
    {
        if ($type === 'serial') {
            return SaleItem::query()
                ->whereIn('sale_id', $saleIds)
                ->where('item_type', 'serial')
                ->join('inventories', 'sale_items.inventory_id', '=', 'inventories.id')
                ->join('products', 'inventories.product_id', '=', 'products.id')
                ->selectRaw("products.id as product_id, products.name as product_name, 'serial' as type, COUNT(*) as total_sold, SUM(sale_items.unit_price) as total_revenue, SUM(sale_items.unit_price - inventories.purchase_price - inventories.extra_cost) as total_profit")
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('total_sold')
                ->limit($limit);
        }

        return SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->where('item_type', 'bulk')
            ->join('accessories', 'sale_items.accessory_id', '=', 'accessories.id')
            ->join('products', 'accessories.product_id', '=', 'products.id')
            ->selectRaw("products.id as product_id, products.name as product_name, 'bulk' as type, SUM(sale_items.quantity) as total_sold, SUM(sale_items.subtotal) as total_revenue, SUM(sale_items.subtotal - accessories.purchase_price * sale_items.quantity) as total_profit")
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_sold')
            ->limit($limit);
    }
}
