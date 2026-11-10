<?php

use App\Http\Controllers\RepairCatalogController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\RepairReportController;
use App\Http\Controllers\RepairSettingsController;
use App\Http\Middleware\RepairEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(RepairEnabled::class)->group(function () {
    Route::match(['get', 'post'], '/repair-tracking', [RepairController::class, 'track'])
        ->middleware('throttle:10,1')->name('repairs.track');

    Route::middleware(['auth:web', 'auth.session'])->prefix('repairs')->name('repairs.')->group(function () {
        Route::get('/reports', RepairReportController::class)->middleware('permission:repairs.reports')->name('reports');
        Route::get('/settings', [RepairSettingsController::class, 'edit'])->middleware('permission:repairs.settings')->name('settings');
        Route::put('/settings', [RepairSettingsController::class, 'update'])->middleware('permission:repairs.settings')->name('settings.update');
        Route::middleware('permission:repairs.settings')->group(function () {
            Route::put('/settings/sms', [RepairSettingsController::class, 'sms'])->middleware(['sms-feature', 'permission:sms.settings'])->name('settings.sms');
            Route::match(['get', 'put'], '/settings/receipt-preview', [RepairSettingsController::class, 'preview'])->name('settings.preview');
            Route::get('/catalog', [RepairCatalogController::class, 'index'])->name('catalog');
            Route::post('/catalog/brands', [RepairCatalogController::class, 'saveBrand'])->name('catalog.brands.store');
            Route::put('/catalog/brands/{brand}', [RepairCatalogController::class, 'saveBrand'])->name('catalog.brands.update');
            Route::post('/catalog/brands/{brand}/models', [RepairCatalogController::class, 'saveModel'])->name('catalog.models.store');
            Route::put('/catalog/brands/{brand}/models/{model}', [RepairCatalogController::class, 'saveModel'])->name('catalog.models.update');
        });
        Route::middleware('permission:repairs.view')->group(function () {
            Route::get('/', [RepairController::class, 'index'])->name('index');
            Route::middleware('permission:repairs.create')->group(function () {
                Route::get('/intake', [RepairController::class, 'create'])->name('create');
                Route::post('/intake', [RepairController::class, 'store'])->name('store');
                Route::get('/customers', [RepairController::class, 'customers'])->name('customers');
                Route::get('/customers/{customer}/history', [RepairController::class, 'history'])->name('customers.history');
                Route::post('/customers', [RepairController::class, 'storeCustomer'])->middleware('permission:customers.create')->name('customers.store');
            });
            Route::get('/parts/search', [RepairController::class, 'products'])->middleware('permission:repairs.parts')->name('products');
            Route::post('/parts/products', [RepairController::class, 'storeProduct'])->middleware('permission:products.create')->name('products.store');
            Route::prefix('{repair}')->whereNumber('repair')->group(function () {
                Route::get('/', [RepairController::class, 'show'])->name('show');
                Route::put('/', [RepairController::class, 'update'])->name('update');
                Route::put('/sms-consent', [RepairController::class, 'consent'])->middleware('sms-feature')->name('consent');
                Route::post('/unlock', [RepairController::class, 'unlock'])->middleware('throttle:12,1')->name('unlock');
                Route::get('/print/{document}', [RepairController::class, 'print'])->whereIn('document', ['intake', 'invoice', 'payment'])->name('print');
                Route::post('/parts', [RepairController::class, 'addPart'])->name('parts.add');
                Route::post('/parts/{part}/{action}', [RepairController::class, 'changePart'])->whereNumber('part')->whereIn('action', ['consume', 'remove'])->name('parts.change');
                Route::post('/payments/{action}', [RepairController::class, 'payment'])->whereIn('action', ['collect', 'refund'])->name('payment');
                Route::post('/payments/{payment}/correct', [RepairController::class, 'correct'])->whereNumber('payment')->name('payment.correct');
                Route::post('/costs', [RepairController::class, 'cost'])->name('cost');
                Route::post('/attachments', [RepairController::class, 'attach'])->name('attach');
                Route::get('/attachments/{attachment}', [RepairController::class, 'download'])->whereNumber('attachment')->name('download');
            });
        });
    });

    Route::middleware(['superadmin.enabled', 'auth:superadmin'])->prefix('superadmin/repairs')->name('superadmin.repairs.')->group(function () {
        Route::put('/settings/sms', [RepairSettingsController::class, 'sms'])->middleware('sms-feature')->name('settings.sms');
        Route::get('/settings', [RepairSettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [RepairSettingsController::class, 'update'])->name('settings.update');
        Route::match(['get', 'put'], '/settings/receipt-preview', [RepairSettingsController::class, 'preview'])->name('settings.preview');
    });
});
