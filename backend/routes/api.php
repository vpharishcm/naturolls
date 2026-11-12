<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('payments/callback', [BillingController::class, 'callback'])->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'idempotent'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('dashboard', [ReportController::class, 'dashboard']);

    // Catalog
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/quick-lists', [CatalogController::class, 'quickLists']);
    Route::get('stock/availability', [CatalogController::class, 'availability']);
    Route::post('products/{product}/favourite', [CatalogController::class, 'favouriteProduct']);
    Route::middleware('perm:catalog.manage')->group(function () {
        Route::post('categories', [CatalogController::class, 'storeCategory']);
        Route::post('categories/reorder', [CatalogController::class, 'reorderCategories']);
        Route::put('categories/{category}', [CatalogController::class, 'updateCategory']);
        Route::delete('categories/{category}', [CatalogController::class, 'destroyCategory']);
        Route::post('products', [CatalogController::class, 'storeProduct']);
        Route::post('products/bulk-prices', [CatalogController::class, 'bulkPrices']);
        Route::put('products/{product}', [CatalogController::class, 'updateProduct']);
        Route::post('products/{product}/image', [CatalogController::class, 'uploadImage']);
        Route::delete('products/{product}', [CatalogController::class, 'destroyProduct']);
    });

    // Orders and order items
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/sync', [OrderController::class, 'sync']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::patch('orders/{order}', [OrderController::class, 'update']);
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('orders/{order}/items', [OrderController::class, 'addItem']);
    Route::patch('orders/{order}/items/{item}', [OrderController::class, 'updateItem']);
    Route::delete('orders/{order}/items/{item}', [OrderController::class, 'removeItem']);
    Route::post('orders/{order}/items/{item}/serve', [OrderController::class, 'serveItem']);
    Route::post('orders/{order}/items/{item}/resolve', [OrderController::class, 'resolveItem']);

    // Billing, invoices and printing
    Route::post('orders/{order}/bill/preview', [BillingController::class, 'preview']);
    Route::post('orders/{order}/bill', [BillingController::class, 'checkout']);
    Route::get('invoices', [BillingController::class, 'invoices']);
    Route::get('invoices/{invoice}', [BillingController::class, 'invoice']);
    Route::get('invoices/{invoice}/receipt', [BillingController::class, 'receipt']);
    Route::post('invoices/{invoice}/print', [BillingController::class, 'printNetwork']);
    Route::post('invoices/{invoice}/print-log', [BillingController::class, 'printLog']);
    Route::post('invoices/{invoice}/refunds', [BillingController::class, 'refund']);
    Route::post('invoices/{invoice}/edit/preview', [BillingController::class, 'previewEdit']);
    Route::post('invoices/{invoice}/edit', [BillingController::class, 'edit']);
    Route::get('printer/status', [BillingController::class, 'printerStatus']);
    Route::get('printer/logs', [BillingController::class, 'printLogs']);

    // Payments
    Route::get('payments', [BillingController::class, 'payments']);
    Route::get('payments/summary', [BillingController::class, 'summary']);
    Route::middleware('perm:payments.manage')->group(function () {
        Route::post('payments/reconcile', [BillingController::class, 'reconcile']);
        Route::post('payments/cash-closing', [BillingController::class, 'closeCash']);
    });

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index']);
    Route::get('inventory/movements', [InventoryController::class, 'movements']);
    Route::middleware('perm:stock.correct')->group(function () {
        Route::post('inventory/reconcile', [InventoryController::class, 'reconcile']);
        Route::post('inventory/{inventory}/correct', [InventoryController::class, 'correct']);
        Route::post('inventory/{inventory}/wastage', [InventoryController::class, 'wastage']);
        Route::post('inventory/{inventory}/opening', [InventoryController::class, 'opening']);
    });

    // Production
    Route::get('production', [ProductionController::class, 'index']);
    Route::post('production', [ProductionController::class, 'store'])->middleware('perm:production.record');
    Route::post('production/items/{item}/reverse', [ProductionController::class, 'reverse']);
    Route::post('production/items/{item}/edit', [ProductionController::class, 'edit']);

    // Customers and loyalty
    Route::get('customers', [CustomerController::class, 'index']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::middleware('perm:customers.manage')->group(function () {
        Route::post('customers', [CustomerController::class, 'store']);
        Route::put('customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
        Route::post('customers/{customer}/loyalty', [CustomerController::class, 'joinLoyalty']);
    });
    Route::post('loyalty/{account}/adjust', [CustomerController::class, 'adjustPoints'])->middleware('perm:loyalty.adjust');

    // Reports
    Route::middleware('perm:reports.view')->group(function () {
        Route::get('reports', [ReportController::class, 'catalog']);
        Route::get('reports/{type}', [ReportController::class, 'show']);
        Route::get('reports/{type}/export/{format}', [ReportController::class, 'export']);
    });

    // Promotions
    Route::get('promotions', [AdminController::class, 'promotions']);
    Route::middleware('perm:promotions.manage')->group(function () {
        Route::post('promotions', [AdminController::class, 'storePromotion']);
        Route::put('promotions/{promotion}', [AdminController::class, 'updatePromotion']);
        Route::delete('promotions/{promotion}', [AdminController::class, 'destroyPromotion']);
    });

    // Staff, settings, audit and backups
    Route::middleware('perm:staff.manage')->group(function () {
        Route::get('staff', [AdminController::class, 'staff']);
        Route::post('staff', [AdminController::class, 'storeStaff']);
        Route::put('staff/{user}', [AdminController::class, 'updateStaff']);
        Route::get('audit-logs', [AdminController::class, 'auditLogs']);
    });
    Route::get('settings', [AdminController::class, 'settings']);
    Route::put('settings', [AdminController::class, 'updateSettings'])->middleware('perm:settings.manage');
    Route::middleware('perm:backups.manage')->group(function () {
        Route::get('backups', [AdminController::class, 'backups']);
        Route::post('backups', [AdminController::class, 'runBackup']);
        Route::get('backups/{backup}/download', [AdminController::class, 'downloadBackup']);
    });
});
