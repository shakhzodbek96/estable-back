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
use App\Http\Controllers\Api\SaleScanController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

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
    Route::get('sales/scan/{barcode}', [SaleScanController::class, 'scanBarcode']);
    Route::get('sales/scan-imei/{imei}', [SaleScanController::class, 'scanImei']);
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);
    Route::delete('sales/{sale}', [SaleController::class, 'destroy']);
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
