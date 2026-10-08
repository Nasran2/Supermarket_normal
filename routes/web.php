<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockBatchController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:20,1');
});
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');
    Route::middleware('permission:pos.access')->prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::get('/products', [PosController::class, 'products'])->name('products');
        Route::post('/customers', [PosController::class, 'storeCustomer'])->name('customers.store');
        Route::get('/checkout-status', [PosController::class, 'checkoutStatus'])->name('checkout-status');
        Route::post('/quote', [PosController::class, 'quote'])->name('quote');
        Route::post('/complete', [PosController::class, 'complete'])->name('complete');
    });
    Route::get('/sales', [SaleController::class, 'index'])->middleware('permission:sales.view')->name('sales.index');
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
    Route::get('/sales/{sale}/edit', [SaleController::class, 'edit'])->middleware(['permission:sales.edit', 'permission:pos.access'])->name('sales.edit');
    Route::get('/sales/{sale}/edit/products', [SaleController::class, 'editProducts'])->middleware(['permission:sales.edit', 'permission:pos.access'])->name('sales.edit.products');
    Route::post('/sales/{sale}/edit/quote', [SaleController::class, 'editQuote'])->name('sales.edit.quote');
    Route::post('/sales/{sale}/edit', [SaleController::class, 'revise'])->name('sales.revise');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware('permission:sales.view')->name('sales.show');
    Route::put('/sales/{sale}', [SaleController::class, 'update'])->name('sales.update');
    Route::post('/sales/{sale}/void', [SaleController::class, 'void'])->name('sales.void');
    Route::delete('/sales/{sale}', [SaleController::class, 'destroy'])->name('sales.destroy');
    Route::post('/sales/{sale}/returns', [SaleController::class, 'returnItems'])->name('sales.returns.store');
    Route::post('/sales/{sale}/collections', [SaleController::class, 'collect'])->name('sales.collections.store');
    Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('permission:purchases.view')->name('purchases.index');
    Route::get('/purchases/create', [PurchaseController::class, 'create'])->middleware('permission:purchases.create')->name('purchases.create');
    Route::get('/purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->middleware('permission:purchases.edit')->name('purchases.edit');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view')->name('purchases.show');
    Route::post('/purchases/{purchase}/void', [PurchaseController::class, 'void'])->name('purchases.void');
    Route::get('/register', [RegisterController::class, 'index'])->middleware('permission:register.view')->name('register.index');
    Route::get('/register/current-summary', [RegisterController::class, 'currentSummary'])->middleware('permission:register.view')->name('register.current-summary');
    Route::post('/register/open', [RegisterController::class, 'open'])->name('register.open');
    Route::post('/register/movement', [RegisterController::class, 'movement'])->name('register.movement');
    Route::post('/register/close', [RegisterController::class, 'close'])->name('register.close');
    Route::get('/register/{register}', [RegisterController::class, 'show'])->middleware('permission:register.view')->name('register.show');
    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.view')->name('settings.index');
    Route::get('/settings/{group}', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::prefix('stock-adjustments')->name('adjustments.')->group(function () {
        Route::get('/', [StockBatchController::class, 'index'])->middleware('permission:products.view')->name('index');
        Route::get('/products', [StockBatchController::class, 'products'])->middleware('permission:products.edit')->name('products');
        Route::get('/create', [StockBatchController::class, 'create'])->middleware('permission:products.edit')->name('create');
        Route::post('/', [StockBatchController::class, 'store'])->middleware('permission:products.edit')->name('store');
        Route::get('/{adjustment}/edit', [StockBatchController::class, 'edit'])->middleware('permission:products.edit')->name('edit');
        Route::get('/{adjustment}', [StockBatchController::class, 'show'])->middleware('permission:products.view')->name('show');
        Route::put('/{adjustment}', [StockBatchController::class, 'update'])->middleware('permission:products.edit')->name('update');
        Route::delete('/{adjustment}', [StockBatchController::class, 'destroy'])->middleware('permission:products.edit')->name('destroy');
    });
    Route::get('/stock/{product}/adjust', [StockController::class, 'edit'])->middleware('permission:products.edit')->name('stock.edit');
    Route::put('/stock/{product}/adjust', [StockController::class, 'update'])->name('stock.update');
    Route::prefix('manage/{resource}')->name('manage.')->group(function () {
        Route::get('/', [ResourceController::class, 'index'])->name('index');
        Route::get('/create', [ResourceController::class, 'create'])->name('create');
        Route::post('/', [ResourceController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [ResourceController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::get('/{id}', [ResourceController::class, 'show'])->whereNumber('id')->name('show');
        Route::put('/{id}', [ResourceController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [ResourceController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });
});
