<?php

use App\Http\Controllers\Admin\AccessoryController;
use App\Http\Controllers\Admin\AccessoryImageController;
use App\Http\Controllers\Admin\AttributeDefinitionController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\InventoryImageController;
use App\Http\Controllers\Admin\InvestorController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\SupplyBatchController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\RateController;
use App\Http\Controllers\Admin\RepairCostController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DebtContactController;
use App\Http\Controllers\Api\QuickCustomerController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalePaymentController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\SaleScanController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\InventoryStatusController;
use App\Http\Controllers\Api\ConsignmentController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\InvestmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Public\CatalogController;
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
    \App\Http\Middleware\TrackTenantActivity::class,
])->group(function () {

// ── Public katalog (mijozlar uchun — AUTH talab qilinmaydi) ──────────────
// Tenant Origin orqali aniqlanadi; faqat xavfsiz (sotuv narxi, mavjudlik,
// rasm, kategoriya) maydonlar qaytariladi.
Route::prefix('catalog')->group(function () {
    Route::get('store', [CatalogController::class, 'store']);
    Route::get('stats', [CatalogController::class, 'stats']);
    Route::get('shops', [CatalogController::class, 'shops']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('products', [CatalogController::class, 'index']);
    Route::get('products/{product}', [CatalogController::class, 'show']);
});

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

    // POS skidka limitlari (sotuvchi ham o'qiy oladi — narx chegarasini ko'rsatish uchun)
    Route::get('settings/discount-limits', [SettingController::class, 'discountLimits']);

    // Chek konfiguratsiyasi (POS ham o'qiydi — chek chiqarishда qo'llaydi)
    Route::get('settings/receipt', [SettingController::class, 'receiptConfig']);

    // Do'kon ma'lumoti (admin sozlamalar formasi o'qiydi)
    Route::get('settings/store-info', [SettingController::class, 'storeInfo']);

    // Sales
    Route::get('sales/search-products', [SaleScanController::class, 'searchProducts']);
    Route::get('sales/scan/{barcode}', [SaleScanController::class, 'scanBarcode']);
    Route::get('sales/scan-imei/{imei}', [SaleScanController::class, 'scanImei']);
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);
    Route::delete('sales/{sale}', [SaleController::class, 'destroy']);
});

// Smena ochish/ko'rish + chiqim yaratish (menejer ham); tasdiqlash admin guruhida
Route::middleware(['auth:sanctum', 'role:admin,manager'])->group(function () {
    Route::get('shifts/current', [ShiftController::class, 'current']);
    Route::get('shifts', [ShiftController::class, 'index']);
    Route::get('shifts/{shift}', [ShiftController::class, 'show']);
    Route::post('shifts', [ShiftController::class, 'open']);
    Route::post('expenses', [ExpenseController::class, 'store']); // pending yaratish
});

// Admin/Manager — to'lovlarni boshqarish
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    // Qarz daftari (Долги) — loyihaning qolgan qismidan mustaqil, owner uchun
    Route::get('debts/summary', [DebtContactController::class, 'summary']);
    Route::apiResource('debts', DebtContactController::class);
    Route::post('debts/{debt}/entries', [DebtContactController::class, 'addEntry']);
    Route::put('debt-entries/{debtEntry}', [DebtContactController::class, 'updateEntry']);
    Route::delete('debt-entries/{debtEntry}', [DebtContactController::class, 'deleteEntry']);

    Route::get('sale-payments/pending', [SalePaymentController::class, 'pending']);
    Route::get('sale-payments/summary', [SalePaymentController::class, 'summary']);
    Route::post('sale-payments/bulk-accept', [SalePaymentController::class, 'bulkAccept']);
    Route::post('sale-payments/bulk-accept-sale', [SalePaymentController::class, 'bulkAcceptSale']);
    Route::get('sale-payments/{salePayment}', [SalePaymentController::class, 'show']);
    Route::put('sale-payments/{salePayment}', [SalePaymentController::class, 'update']);
    Route::post('sale-payments/{salePayment}/accept', [SalePaymentController::class, 'accept']);
    Route::post('sale-payments/{salePayment}/reject', [SalePaymentController::class, 'reject']);
    Route::get('sellers/{user}/cash-summary', [SalePaymentController::class, 'cashSummary']);

    // Kassa chiqimlari — tasdiqlash/rad (faqat admin)
    Route::get('expenses/pending', [ExpenseController::class, 'pending']);
    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expenses/{expense}/accept', [ExpenseController::class, 'accept']);
    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject']);

    // Smena yopish — naqд qabul qilish (faqat admin/rahbar)
    Route::post('shifts/{shift}/close', [ShiftController::class, 'close']);

    // Returns
    Route::get('returns', [ReturnController::class, 'index']);
    Route::post('returns', [ReturnController::class, 'store']);
    Route::get('returns/by-sale/{sale}', [ReturnController::class, 'bySale']);
    Route::get('returns/{return}', [ReturnController::class, 'show']);
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve']);
    Route::post('returns/{return}/reject', [ReturnController::class, 'reject']);

    // Inventory status changes
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

    Route::post('shops/{shop}/image', [ShopController::class, 'uploadImage']);
    Route::delete('shops/{shop}/image', [ShopController::class, 'deleteImage']);
    Route::apiResource('shops', ShopController::class);
    Route::post('categories/{category}/image', [CategoryController::class, 'uploadImage']);
    Route::delete('categories/{category}/image', [CategoryController::class, 'deleteImage']);
    Route::apiResource('categories', CategoryController::class);

    // Dinamik tovar xususiyatlari (atribut ta'riflari) — reusable
    Route::apiResource('attribute-definitions', AttributeDefinitionController::class);

    Route::post('products/bulk', [ProductController::class, 'bulkStore']);
    Route::post('products/bulk-update-category', [ProductController::class, 'bulkUpdateCategory']);
    Route::post('products/import', [ProductController::class, 'import']);
    Route::get('products/import-template', [ProductController::class, 'importTemplate']);
    Route::post('products/{product}/images', [ProductImageController::class, 'store']);
    Route::put('products/{product}/images/{image}/primary', [ProductImageController::class, 'primary']);
    Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('investors', InvestorController::class);
    Route::apiResource('partners', PartnerController::class);
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('suppliers/{supplier}/batches', [SupplierController::class, 'batches']);
    Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'storePayment']);
    Route::get('supply-batches', [SupplyBatchController::class, 'index']);
    Route::apiResource('rates', RateController::class)->only(['index', 'store']);

    // POS skidka limitlari (faqat admin o'rnatadi)
    Route::put('discount-limits', [SettingController::class, 'updateDiscountLimits']);

    // Chek konfiguratsiyasi (faqat admin o'rnatadi)
    Route::put('settings/receipt', [SettingController::class, 'updateReceiptConfig']);

    // Do'kon ma'lumoti (faqat admin o'rnatadi)
    Route::put('settings/store-info', [SettingController::class, 'updateStoreInfo']);

    // Telegram — tenant maqsadli chat_id'lari (markaziy bot orqali). Faqat admin.
    Route::get('settings/telegram', [SettingController::class, 'telegramConfig']);
    Route::put('settings/telegram', [SettingController::class, 'updateTelegramConfig']);
    Route::post('settings/telegram/send', [SettingController::class, 'sendTelegramNow']);
    // Xabar shablonlari (foydalanuvchi tahrirlaydi, 3 soat keshlanadi)
    Route::get('settings/telegram/templates', [SettingController::class, 'telegramTemplates']);
    Route::put('settings/telegram/templates', [SettingController::class, 'updateTelegramTemplates']);

    // Inventories
    Route::get('inventories/search', [InventoryController::class, 'search']);
    Route::get('inventories/by-status/{status}', [InventoryController::class, 'byStatus']);
    Route::get('inventories/price-preview', [InventoryController::class, 'pricePreview']);
    Route::get('inventories/price-search', [InventoryController::class, 'priceSearch']);
    Route::post('inventories/bulk-update-price', [InventoryController::class, 'bulkUpdatePrice']);
    Route::post('inventories/bulk', [InventoryController::class, 'bulkStore']);
    Route::post('inventories/import', [InventoryController::class, 'import']);
    Route::get('inventories/import-template', [InventoryController::class, 'importTemplate']);
    Route::post('inventories/{inventory}/images', [InventoryImageController::class, 'store']);
    Route::put('inventories/{inventory}/images/{image}/primary', [InventoryImageController::class, 'primary']);
    Route::delete('inventories/{inventory}/images/{image}', [InventoryImageController::class, 'destroy']);
    Route::apiResource('inventories', InventoryController::class);
    Route::get('inventories/{inventory}/repair-costs', [RepairCostController::class, 'index']);
    Route::post('inventories/{inventory}/repair-costs', [RepairCostController::class, 'store']);

    // Accessories
    Route::get('accessories/search', [AccessoryController::class, 'search']);
    Route::post('accessories/bulk', [AccessoryController::class, 'bulkStore']);
    Route::post('accessories/import', [AccessoryController::class, 'import']);
    Route::get('accessories/import-template', [AccessoryController::class, 'importTemplate']);
    Route::post('accessories/{accessory}/restock', [AccessoryController::class, 'restock']);
    Route::post('accessories/{accessory}/images', [AccessoryImageController::class, 'store']);
    Route::put('accessories/{accessory}/images/{image}/primary', [AccessoryImageController::class, 'primary']);
    Route::delete('accessories/{accessory}/images/{image}', [AccessoryImageController::class, 'destroy']);
    Route::apiResource('accessories', AccessoryController::class);
});

}); // end tenant middleware group
