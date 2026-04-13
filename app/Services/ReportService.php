<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\SalePaymentStatus;
use App\Models\Accessory;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * 1. Dashboard — asosiy ko'rsatkichlar
     */
    public function dashboard(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $shopId = $filters['shop_id'] ?? null;

        // Tasdiqlangan sotuvlar
        $salesQuery = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));

        $salesCount = (clone $salesQuery)->count();
        $totalRevenue = (float) (clone $salesQuery)->sum('total_price');

        // Tannarx hisoblash
        $saleIds = (clone $salesQuery)->pluck('id');
        $totalCost = $this->calculateCost($saleIds);
        $grossProfit = $totalRevenue - $totalCost;

        // To'lov turlari bo'yicha
        $byPaymentMethod = SalePayment::query()
            ->where('status', SalePaymentStatus::Accepted)
            ->whereIn('sale_id', $saleIds)
            ->selectRaw("type, SUM(CASE WHEN currency = 'usd' THEN amount ELSE amount / NULLIF(rate, 0) END) as total_usd")
            ->groupBy('type')
            ->pluck('total_usd', 'type')
            ->map(fn($v) => round((float) $v, 2));

        // Harajatlar
        $expensesQuery = Transaction::query()
            ->where('is_credit', false)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));

        $totalExpenses = (float) (clone $expensesQuery)->sum('amount');

        // Inventar qoldiq
        $serialQuery = Inventory::query()
            ->where('status', InventoryStatus::InStock)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));

        $serialCount = (clone $serialQuery)->count();
        $serialValue = (float) (clone $serialQuery)->selectRaw('SUM(purchase_price + extra_cost) as total')->value('total') ?: 0;

        $accessoryQuery = Accessory::query()
            ->where('is_active', true)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id));

        $accessoriesCount = (int) (clone $accessoryQuery)->selectRaw('SUM(quantity - sold_quantity - consigned_quantity) as total')->value('total') ?: 0;
        $accessoriesValue = (float) (clone $accessoryQuery)->selectRaw('SUM(purchase_price * (quantity - sold_quantity - consigned_quantity)) as total')->value('total') ?: 0;

        // Tasdiqlanmagan to'lovlar
        $pendingPayments = (float) SalePayment::query()
            ->where('status', SalePaymentStatus::New)
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("SUM(CASE WHEN currency = 'usd' THEN amount ELSE amount / NULLIF(rate, 0) END) as total_usd")
            ->value('total_usd') ?: 0;

        // Top 5 tovarlar
        $topProducts = $this->topProductsQuery($saleIds, 'serial', 5)
            ->union($this->topProductsQuery($saleIds, 'bulk', 5))
            ->get()
            ->sortByDesc('total_sold')
            ->take(5)
            ->values();

        // Oxirgi 10 sotuv
        $recentSales = Sale::with(['customer:id,name', 'seller:id,name'])
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'customer_id', 'sold_by', 'sale_date', 'total_price', 'payment_method']);

        // Kunlik sotuvlar (grafik uchun)
        $dailySales = Sale::query()
            ->whereBetween('sale_date', [$dateFrom, $dateTo])
            ->whereHas('payments', fn($q) => $q->where('status', SalePaymentStatus::Accepted))
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("sale_date as date, COUNT(*) as count, SUM(total_price) as revenue")
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();

        return [
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'sales' => [
                'count' => $salesCount,
                'total_revenue' => round($totalRevenue, 2),
                'total_cost' => round($totalCost, 2),
                'gross_profit' => round($grossProfit, 2),
                'by_payment_method' => $byPaymentMethod,
            ],
            'expenses' => ['total' => round($totalExpenses, 2)],
            'net_profit' => round($grossProfit - $totalExpenses, 2),
            'inventory' => [
                'serial_count' => $serialCount,
                'serial_value' => round($serialValue, 2),
                'accessories_count' => $accessoriesCount,
                'accessories_value' => round($accessoriesValue, 2),
            ],
            'pending_payments' => round($pendingPayments, 2),
            'top_products' => $topProducts,
            'recent_sales' => $recentSales,
            'daily_sales' => $dailySales,
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

        // Harajatlar davr bo'yicha
        $expenses = Transaction::query()
            ->where('is_credit', false)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->when($shopId, fn($q, $id) => $q->where('shop_id', $id))
            ->selectRaw("{$dateExpr} as period, SUM(amount) as total")
            ->groupByRaw(str_replace('sale_date', 'transaction_date', $dateExpr))
            ->orderByRaw(str_replace('sale_date', 'transaction_date', $dateExpr))
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
        $salesReceived = $investments->where('type', 3)->where('is_credit', true)->sum('total');
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
