<?php

use App\Http\Controllers\Admin\AccessoryController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\InvestorController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RateController;
use App\Http\Controllers\Admin\RepairCostController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\QuickCustomerController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalePaymentController;
use App\Http\Controllers\Api\SaleScanController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\InventoryStatusController;
use App\Http\Controllers\Api\ConsignmentController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\InvestmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Middleware\InitializeTenancyByOriginHeader;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant API Routes
|--------------------------------------------------------------------------
|
| Bu fayldagi barcha endpointlar TENANT kontekstida ishlaydi.
| Tenant `Origin` header orqali aniqlanadi (masalan:
|   Origin: https://shop1.estable.uz  →  tenant = shop1.estable.uz).
|
| Agar Origin yo'q bo'lsa yoki bu domain hech qanday tenantga biriktirilmagan
| bo'lsa — 400/404 xatolik qaytadi.
|
| Central endpointlar alohida faylda: routes/central.php (/api/central/*).
|
| NOTE: PreventAccessFromCentralDomains middleware'i olib tashlandi, chunki
| u request'ning `Host` header'ini tekshiradi (doim `api.estable.uz`). Bizda
| tenant identifikatsiya Origin orqali bo'lgani uchun, agar Origin noto'g'ri
| bo'lsa InitializeTenancyByOriginHeader o'zi xatolik qaytaradi.
|
*/

Route::middleware([
    InitializeTenancyByOriginHeader::class,
])->group(function () {

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

// Auth required (seller ham foydalanishi mumkin)
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('customers', CustomerController::class);
    Route::post('customers/quick', [QuickCustomerController::class, 'store']);
    Route::get('rates/current', [RateController::class, 'current']);

    // Sales
    Route::get('sales/search-products', [SaleScanController::class, 'searchProducts']);
    Route::get('sales/scan/{barcode}', [SaleScanController::class, 'scanBarcode']);
    Route::get('sales/scan-imei/{imei}', [SaleScanController::class, 'scanImei']);
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);
    Route::delete('sales/{sale}', [SaleController::class, 'destroy']);
});

// Admin/Manager — to'lovlarni boshqarish
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('sale-payments/pending', [SalePaymentController::class, 'pending']);
    Route::post('sale-payments/bulk-accept', [SalePaymentController::class, 'bulkAccept']);
    Route::get('sale-payments/{salePayment}', [SalePaymentController::class, 'show']);
    Route::post('sale-payments/{salePayment}/accept', [SalePaymentController::class, 'accept']);
    Route::post('sale-payments/{salePayment}/reject', [SalePaymentController::class, 'reject']);
    Route::get('sellers/{user}/cash-summary', [SalePaymentController::class, 'cashSummary']);

    // Returns
    Route::get('returns', [ReturnController::class, 'index']);
    Route::post('returns', [ReturnController::class, 'store']);
    Route::get('returns/by-sale/{sale}', [ReturnController::class, 'bySale']);
    Route::get('returns/{return}', [ReturnController::class, 'show']);
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve']);
    Route::post('returns/{return}/reject', [ReturnController::class, 'reject']);

    // Inventory status changes
    Route::post('inventories/{inventory}/send-to-repair', [InventoryStatusController::class, 'sendToRepair']);
    Route::post('inventories/{inventory}/return-from-repair', [InventoryStatusController::class, 'returnFromRepair']);
    Route::post('inventories/{inventory}/write-off', [InventoryStatusController::class, 'writeOff']);

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('transactions/{transaction}', [TransactionController::class, 'show']);

    // Investments (investor <-> admin hisob-kitoblari)
    Route::get('investments', [InvestmentController::class, 'index']);
    Route::get('investments/totals', [InvestmentController::class, 'totals']);
    Route::post('investments', [InvestmentController::class, 'store']);
    Route::put('investments/{investment}', [InvestmentController::class, 'update']);
    Route::delete('investments/{investment}', [InvestmentController::class, 'destroy']);

    // Consignments
    Route::get('consignments', [ConsignmentController::class, 'index']);
    Route::post('consignments', [ConsignmentController::class, 'store']);
    Route::post('consignments/report-sale', [ConsignmentController::class, 'reportSale']);
    Route::post('consignments/pay-partner', [ConsignmentController::class, 'payPartner']);
    Route::get('consignments/{consignment}', [ConsignmentController::class, 'show']);
    Route::post('consignments/{consignment}/return-items', [ConsignmentController::class, 'returnItems']);
    Route::post('consignments/{consignment}/cancel', [ConsignmentController::class, 'cancel']);
    Route::get('partners/{partner}/consignments', [ConsignmentController::class, 'partnerConsignments']);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('dashboard', [ReportController::class, 'dashboard']);
        Route::get('profit', [ReportController::class, 'profit']);
        Route::get('inventory', [ReportController::class, 'inventory']);
        Route::get('top-products', [ReportController::class, 'topProducts']);
        Route::get('sellers', [ReportController::class, 'sellers']);
        Route::get('top-customers', [ReportController::class, 'topCustomers']);
        Route::get('investors/{investor}', [ReportController::class, 'investorReport']);
        Route::get('expenses', [ReportController::class, 'expenses']);
    });
});

// Admin only
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);
    Route::post('users/{user}/toggle-block', [UserController::class, 'toggleBlock']);

    Route::apiResource('shops', ShopController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::post('products/bulk', [ProductController::class, 'bulkStore']);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('investors', InvestorController::class);
    Route::apiResource('partners', PartnerController::class);
    Route::apiResource('rates', RateController::class)->only(['index', 'store']);

    // Inventories
    Route::get('inventories/search', [InventoryController::class, 'search']);
    Route::get('inventories/by-status/{status}', [InventoryController::class, 'byStatus']);
    Route::post('inventories/bulk', [InventoryController::class, 'bulkStore']);
    Route::apiResource('inventories', InventoryController::class);
    Route::get('inventories/{inventory}/repair-costs', [RepairCostController::class, 'index']);
    Route::post('inventories/{inventory}/repair-costs', [RepairCostController::class, 'store']);

    // Accessories
    Route::get('accessories/search', [AccessoryController::class, 'search']);
    Route::post('accessories/{accessory}/restock', [AccessoryController::class, 'restock']);
    Route::apiResource('accessories', AccessoryController::class);
});

}); // end tenant middleware group
