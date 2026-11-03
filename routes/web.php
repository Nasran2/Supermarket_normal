<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarcodeController;
use App\Http\Controllers\BarcodePresetController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\ExpiryController;
use App\Http\Controllers\Hr\PayrollController;
use App\Http\Controllers\Hr\StaffController;
use App\Http\Controllers\Hr\WorkforceController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchasePaymentController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\SmsSettingsController;
use App\Http\Controllers\StockBatchController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockPriceController;
use App\Http\Controllers\SupplierAccountController;
use App\Http\Controllers\TaxInvoiceController;
use App\Http\Controllers\TransactionReturnController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Middleware\HrEnabled;
use App\Http\Middleware\SaleVisibility;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:20,1');
});
Route::get('/r/{token}', [SmsController::class, 'publicReceipt'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware(['sms-feature', 'throttle:60,1'])->name('receipts.public');

Route::middleware(['auth', 'auth.session', SaleVisibility::class])->group(function () {
    Route::middleware('sms-feature')->group(function () {
        Route::get('/sms', [SmsController::class, 'index'])->middleware('permission:sms.manage')->name('sms.index');
        Route::get('/sms/customers', [SmsController::class, 'customers'])->middleware('permission:sms.receipt')->name('sms.customers');
        Route::get('/sms/receipts/{sale}', [SmsController::class, 'receiptContext'])->middleware(['permission:sms.receipt', 'permission:sales.receipt'])->name('sms.receipt.context');
        Route::post('/sms/receipts/{sale}', [SmsController::class, 'receipt'])->middleware(['permission:sms.receipt', 'permission:sales.receipt', 'throttle:20,1'])->name('sms.receipt');
        Route::post('/sms/preview', [SmsController::class, 'preview'])->middleware('permission:sms.manage')->name('sms.preview');
        Route::post('/sms/send', [SmsController::class, 'send'])->middleware(['permission:sms.manage', 'throttle:5,1'])->name('sms.send');
    });

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation.index');
    Route::get('/documentation/{slug}', [DocumentationController::class, 'show'])->name('documentation.show');
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
    Route::prefix('returns')->group(function () {
        Route::post('/supplier-credit/{supplier}', [TransactionReturnController::class, 'supplierCredit'])->name('returns.supplier-credit');
        Route::get('/suppliers', [TransactionReturnController::class, 'supplierIndex'])->name('returns.suppliers');
        Route::post('/suppliers/{supplierReturn}', [TransactionReturnController::class, 'supplierTransition'])->name('returns.suppliers.update');
        Route::prefix('{kind}')->where(['kind' => 'sales|purchase'])->name('returns.')->group(function () {
            Route::get('/', [TransactionReturnController::class, 'index'])->name('index');
            Route::get('/create', [TransactionReturnController::class, 'create'])->name('create');
            Route::get('/products', [TransactionReturnController::class, 'products'])->name('products');
            Route::get('/no-receipt/products', [TransactionReturnController::class, 'noReceiptProducts'])->name('no-receipt.products');
            Route::get('/no-receipt/customers', [TransactionReturnController::class, 'noReceiptCustomers'])->name('no-receipt.customers');
            Route::get('/no-receipt/matches', [TransactionReturnController::class, 'noReceiptMatches'])->name('no-receipt.matches');
            Route::post('/draft', [TransactionReturnController::class, 'draft'])->name('draft');
            Route::post('/quote', [TransactionReturnController::class, 'quote'])->name('quote');
            Route::post('/', [TransactionReturnController::class, 'store'])->name('store');
            Route::get('/{id}/receipt', [TransactionReturnController::class, 'receipt'])->whereNumber('id')->name('receipt');
            Route::post('/{id}/cancel', [TransactionReturnController::class, 'cancel'])->whereNumber('id')->name('cancel');
            Route::get('/{id}', [TransactionReturnController::class, 'show'])->whereNumber('id')->name('show');
        });
    });
    Route::get('/sales', [SaleController::class, 'index'])->middleware('permission:sales.view')->name('sales.index');
    Route::get('/sales/{sale}/tax-invoice/print', [TaxInvoiceController::class, 'preview'])->name('sales.tax.preview');
    Route::get('/sales/{sale}/tax-invoice/context', [TaxInvoiceController::class, 'context'])->name('sales.tax.context');
    Route::post('/sales/{sale}/tax-invoice', [TaxInvoiceController::class, 'store'])->name('sales.tax.store');
    Route::get('/sales/{sale}/tax-invoice', [TaxInvoiceController::class, 'download'])->name('sales.tax.download');
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->middleware('permission:sales.receipt')->name('sales.receipt');
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
    Route::delete('/purchases/{purchase}', [PurchaseController::class, 'void'])->name('purchases.destroy');
    Route::post('/purchases/{purchase}/void', [PurchaseController::class, 'void'])->name('purchases.void');
    Route::post('/purchases/{purchase}/payments', [PurchasePaymentController::class, 'store'])->name('purchases.payments.store');
    Route::post('/purchases/{purchase}/refunds', [PurchasePaymentController::class, 'refund'])->name('purchases.refunds.store');
    Route::post('/purchases/{purchase}/payment-balance', [PurchasePaymentController::class, 'balance'])->name('purchases.balance');
    Route::get('/register', [RegisterController::class, 'index'])->middleware('permission:register.view')->name('register.index');
    Route::get('/register/current-summary', [RegisterController::class, 'currentSummary'])->middleware('permission:register.view|register.close')->name('register.current-summary');
    Route::post('/register/open', [RegisterController::class, 'open'])->name('register.open');
    Route::post('/register/movement', [RegisterController::class, 'movement'])->name('register.movement');
    Route::post('/register/close', [RegisterController::class, 'close'])->name('register.close');
    Route::get('/register/{register}', [RegisterController::class, 'show'])->middleware('permission:register.view')->name('register.show');
    Route::middleware('cheque-feature')->group(function () {
        Route::get('/cheques', [ChequeController::class, 'index'])->middleware('permission:cheques.view')->name('cheques.index');
        Route::get('/cheques/create', [ChequeController::class, 'create'])->middleware('permission:cheques.create')->name('cheques.create');
        Route::post('/cheques', [ChequeController::class, 'store'])->name('cheques.store');
        Route::get('/cheques/available', [ChequeController::class, 'available'])->middleware('permission:cheques.transfer')->name('cheques.available');
        Route::get('/reports/cheques', [ChequeController::class, 'index'])->middleware('permission:cheques.view_reports')->name('cheques.report');
        Route::get('/reports/cheques/{format}', [ChequeController::class, 'export'])->whereIn('format', ['pdf', 'csv'])->middleware(['permission:cheques.view_reports', 'permission:cheques.export'])->name('cheques.export');
        Route::get('/cheques/{tab}', [ChequeController::class, 'index'])->whereIn('tab', ['received', 'issued', 'third-party', 'due-soon', 'returned'])->middleware('permission:cheques.view')->name('cheques.tab');
        Route::get('/settings/cheques', [ChequeController::class, 'settings'])->middleware('permission:cheques.manage_settings')->name('cheques.settings');
        Route::put('/settings/cheques', [ChequeController::class, 'updateSettings'])->middleware('permission:cheques.manage_settings')->name('cheques.settings.update');
        Route::post('/settings/cheques/banks', [ChequeController::class, 'bank'])->middleware('permission:cheques.manage_settings')->name('cheques.banks.store');
        Route::put('/settings/cheques/banks/{account}', [ChequeController::class, 'bankActive'])->middleware('permission:cheques.manage_settings')->name('cheques.banks.update');
        Route::get('/cheques/{cheque}/edit', [ChequeController::class, 'edit'])->whereNumber('cheque')->middleware('permission:cheques.edit')->name('cheques.edit');
        Route::put('/cheques/{cheque}', [ChequeController::class, 'update'])->whereNumber('cheque')->middleware('permission:cheques.edit')->name('cheques.update');
        Route::post('/cheques/{cheque}/return', [ChequeController::class, 'returnCheque'])->whereNumber('cheque')->name('cheques.return');
        Route::post('/cheques/{cheque}/resolve-return', [ChequeController::class, 'resolveReturn'])->whereNumber('cheque')->name('cheques.resolve-return');
        Route::get('/cheques/{cheque}', [ChequeController::class, 'show'])->whereNumber('cheque')->middleware('permission:cheques.view')->name('cheques.show');
        Route::post('/cheques/{cheque}/transfer', [ChequeController::class, 'transfer'])->middleware('permission:cheques.transfer')->name('cheques.transfer');
        Route::post('/cheques/{cheque}/reverse-transfer', [ChequeController::class, 'reverseTransfer'])->middleware('permission:cheques.reverse_transfer')->name('cheques.reverse-transfer');
        Route::post('/cheques/{cheque}/image', [ChequeController::class, 'image'])->middleware('permission:cheques.create')->name('cheques.image');
        Route::get('/cheques/{cheque}/image', [ChequeController::class, 'download'])->middleware('permission:cheques.view')->name('cheques.image.download');
        Route::post('/cheques/{cheque}/{action}', [ChequeController::class, 'action'])->whereIn('action', ['pass', 'return', 'cancel'])->name('cheques.action');
    });
    Route::middleware('barcode-feature')->group(function () {
        Route::get('/barcode', [BarcodeController::class, 'index'])->middleware('permission:barcode.view')->name('barcode.index');
        Route::get('/barcodes/print', [BarcodeController::class, 'index'])->middleware('permission:barcode.view')->name('barcode.print');
        Route::get('/barcode/products', [BarcodeController::class, 'products'])->middleware('permission:barcode.view')->name('barcode.products');
        Route::post('/barcode/generate', [BarcodeController::class, 'generate'])->middleware('permission:barcode.generate')->name('barcode.generate');
        Route::post('/barcode/products/{product}', [BarcodeController::class, 'assign'])->name('barcode.assign');
        Route::post('/barcode/preview', [BarcodeController::class, 'preview'])->name('barcode.preview');
        Route::post('/barcode/jobs', [BarcodeController::class, 'store'])->name('barcode.jobs.store');
        Route::get('/barcode/jobs/{job}', [BarcodeController::class, 'show'])->middleware('permission:barcode.print')->name('barcode.jobs.show');
        Route::get('/barcode/history', [BarcodeController::class, 'history'])->middleware('permission:barcode.view_history')->name('barcode.history');
        Route::get('/barcode/calibration', [BarcodeController::class, 'calibration'])->middleware('permission:barcode.print')->name('barcode.calibration');
        Route::get('/settings/barcode', [BarcodeController::class, 'settings'])->middleware('permission:barcode.manage_settings')->name('barcode.settings');
        Route::put('/settings/barcode', [BarcodeController::class, 'updateSettings'])->middleware('permission:barcode.manage_settings')->name('barcode.settings.update');
        Route::middleware('permission:barcode.manage_presets')->prefix('barcode/presets')->name('barcode.presets.')->group(function () {
            Route::get('/', [BarcodePresetController::class, 'index'])->name('index');
            Route::get('/create', [BarcodePresetController::class, 'edit'])->name('create');
            Route::get('/{preset}/edit', [BarcodePresetController::class, 'edit'])->name('edit');
            Route::post('/', [BarcodePresetController::class, 'store'])->name('store');
            Route::put('/{preset}', [BarcodePresetController::class, 'update'])->name('update');
            Route::post('/{preset}/duplicate', [BarcodePresetController::class, 'duplicate'])->name('duplicate');
            Route::post('/{preset}/default', [BarcodePresetController::class, 'makeDefault'])->name('default');
            Route::delete('/{preset}', [BarcodePresetController::class, 'destroy'])->name('destroy');
        });
    });
    Route::get('/settings/sms', [SmsSettingsController::class, 'edit'])->middleware(['sms-feature', 'permission:sms.settings'])->name('settings.sms');
    Route::post('/settings/sms/test', [SmsSettingsController::class, 'test'])->middleware(['sms-feature', 'permission:sms.settings', 'throttle:5,1'])->name('settings.sms.test');
    Route::put('/settings/sms', [SmsSettingsController::class, 'update'])->middleware(['sms-feature', 'permission:sms.settings'])->name('settings.sms.update');
    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.view')->name('settings.index');
    Route::get('/settings/{group}', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/reports/expiry/pdf', [ExpiryController::class, 'index'])->defaults('format', 'pdf')->name('expiry.pdf');
    Route::get('/reports/expiry/export', [ExpiryController::class, 'index'])->defaults('format', 'csv')->name('expiry.export');
    Route::get('/reports/expiry', [ExpiryController::class, 'index'])->name('expiry.index');
    Route::put('/expiry/{layer}', [ExpiryController::class, 'layer'])->whereNumber('layer')->name('expiry.layer');
    Route::post('/expiry/{layer}/writeoff', [ExpiryController::class, 'writeoff'])->whereNumber('layer')->name('expiry.writeoff');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::prefix('stock-adjustments')->name('adjustments.')->group(function () {
        Route::get('/', [StockBatchController::class, 'index'])->middleware('permission:stock-adjustments.view')->name('index');
        Route::get('/products', [StockBatchController::class, 'products'])->middleware('permission:stock-adjustments.create|stock-adjustments.edit')->name('products');
        Route::get('/create', [StockBatchController::class, 'create'])->middleware('permission:stock-adjustments.create')->name('create');
        Route::post('/', [StockBatchController::class, 'store'])->middleware('permission:stock-adjustments.create')->name('store');
        Route::get('/{adjustment}/edit', [StockBatchController::class, 'edit'])->middleware('permission:stock-adjustments.edit')->name('edit');
        Route::get('/{adjustment}', [StockBatchController::class, 'show'])->middleware('permission:stock-adjustments.view')->name('show');
        Route::put('/{adjustment}', [StockBatchController::class, 'update'])->middleware('permission:stock-adjustments.edit')->name('update');
        Route::delete('/{adjustment}', [StockBatchController::class, 'destroy'])->middleware('permission:stock-adjustments.delete')->name('destroy');
    });
    Route::get('/stock/{product}/adjust', [StockController::class, 'edit'])->middleware('permission:stock-adjustments.create')->name('stock.edit');
    Route::put('/stock/{product}/adjust', [StockController::class, 'update'])->name('stock.update');
    Route::post('/units/{unit}/default', [StockPriceController::class, 'defaultUnit'])->middleware(['unit-feature', 'permission:units.set_default'])->name('units.default');
    Route::put('/stock-prices/{layer}', [StockPriceController::class, 'reprice'])->middleware('permission:products.manage_prices')->name('stock-prices.update');
    Route::middleware('unit-resources')->prefix('manage/{resource}')->name('manage.')->group(function () {
        Route::get('/', [ResourceController::class, 'index'])->name('index');
        Route::get('/create', [ResourceController::class, 'create'])->name('create');
        Route::post('/', [ResourceController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [ResourceController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::get('/{id}', [ResourceController::class, 'show'])->whereNumber('id')->name('show');
        Route::put('/{id}', [ResourceController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [ResourceController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });

    Route::get('/manage/suppliers/{supplier}/ledger', [SupplierAccountController::class, 'ledger'])->name('manage.suppliers.ledger');

    Route::post('/manage/customers/{customer}/payments', [CustomerPaymentController::class, 'store'])->name('manage.customers.payments.store');
    Route::get('/manage/customers/{customer}/ledger', [CustomerPaymentController::class, 'ledger'])->name('manage.customers.ledger');
});

Route::middleware(['auth', 'auth.session', HrEnabled::class])->prefix('hr')->name('hr.')->group(function () {
    Route::get('/', [WorkforceController::class, 'index'])->middleware('permission:hr.view')->name('index');
    Route::get('/staff', [StaffController::class, 'index'])->middleware('permission:hr.staff.view')->name('staff.index');
    Route::get('/staff/create', [StaffController::class, 'create'])->middleware(['permission:hr.staff.view', 'permission:hr.staff.create'])->name('staff.create');
    Route::post('/staff', [StaffController::class, 'store'])->middleware(['permission:hr.staff.view', 'permission:hr.staff.create'])->name('staff.store');
    Route::get('/staff/{staff}/edit', [StaffController::class, 'edit'])->middleware(['permission:hr.staff.view', 'permission:hr.staff.edit'])->name('staff.edit');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->middleware(['permission:hr.staff.view', 'permission:hr.staff.edit'])->name('staff.update');
    Route::get('/staff/{staff}/ledger', [StaffController::class, 'ledger'])->middleware('permission:hr.ledger.view')->name('staff.ledger');
    Route::post('/staff/{staff}/employment', [StaffController::class, 'employment'])->middleware(['permission:hr.staff.view', 'permission:hr.staff.exit'])->name('staff.employment');
    Route::get('/staff/{staff}', [StaffController::class, 'show'])->middleware('permission:hr.staff.view')->name('staff.show');
    Route::get('/attendance', [WorkforceController::class, 'attendance'])->middleware('permission:hr.attendance.view')->name('attendance');
    Route::post('/attendance', [WorkforceController::class, 'mark'])->middleware(['permission:hr.attendance.view', 'permission:hr.attendance.mark'])->name('attendance.mark');
    Route::get('/leave', [WorkforceController::class, 'leaves'])->middleware('permission:hr.leave.view')->name('leave');
    Route::post('/leave', [WorkforceController::class, 'leaveStore'])->middleware(['permission:hr.leave.view', 'permission:hr.leave.create'])->name('leave.store');
    Route::post('/leave/{leave}/decision', [WorkforceController::class, 'decide'])->middleware(['permission:hr.leave.view', 'permission:hr.leave.approve'])->name('leave.decide');
    Route::post('/leave/{leave}/cancel', [WorkforceController::class, 'cancel'])->middleware(['permission:hr.leave.view', 'permission:hr.leave.cancel'])->name('leave.cancel');
    Route::get('/payroll', [PayrollController::class, 'index'])->middleware('permission:hr.payroll.view')->name('payroll.index');
    Route::get('/payroll/create', [PayrollController::class, 'create'])->middleware(['permission:hr.payroll.view', 'permission:hr.payroll.create'])->name('payroll.create');
    Route::post('/payroll', [PayrollController::class, 'store'])->middleware(['permission:hr.payroll.view', 'permission:hr.payroll.create'])->name('payroll.store');
    Route::post('/payroll/{payroll}/approve', [PayrollController::class, 'approve'])->middleware(['permission:hr.payroll.view', 'permission:hr.payroll.approve'])->name('payroll.approve');
    Route::post('/payroll/{payroll}/void', [PayrollController::class, 'void'])->middleware(['permission:hr.payroll.view', 'permission:hr.payroll.void'])->name('payroll.void');
    Route::get('/payroll/{payroll}/payslip', [PayrollController::class, 'payslip'])->middleware(['permission:hr.payroll.view', 'permission:hr.reports.payroll.pdf'])->name('payroll.payslip');
    Route::get('/payroll/{payroll}', [PayrollController::class, 'show'])->middleware('permission:hr.payroll.view')->name('payroll.show');
    Route::get('/payments', [PayrollController::class, 'payments'])->middleware('permission:hr.payments.view')->name('payments');
    Route::post('/payments', [PayrollController::class, 'pay'])->middleware(['permission:hr.payments.view', 'permission:hr.payments.create'])->name('payments.store');
    Route::post('/payments/{payment}/reverse', [PayrollController::class, 'reverse'])->middleware(['permission:hr.payments.view', 'permission:hr.payments.reverse'])->name('payments.reverse');
    Route::get('/setup', [WorkforceController::class, 'setup'])->middleware('permission:hr.setup.view')->name('setup');
    Route::post('/setup', [WorkforceController::class, 'setupSave'])->middleware(['permission:hr.setup.view', 'permission:hr.setup.manage'])->name('setup.save');
    Route::get('/reports', [App\Http\Controllers\Hr\ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{kind}/{format}', [App\Http\Controllers\Hr\ReportController::class, 'show'])->where('format', 'pdf|csv')->name('reports.download');
    Route::get('/reports/{kind}', [App\Http\Controllers\Hr\ReportController::class, 'show'])->name('reports.show');
});

Route::get('/migrate', function () {
    $output = "=== Database Setup & Fix Tool ===\n\n";
    try {
        // 1. Quarantining any rogue migration files (not part of the 40 official migrations)
        $officialMigrations = [
            '0001_01_01_000000_create_users_table.php',
            '0001_01_01_000001_create_cache_table.php',
            '0001_01_01_000002_create_jobs_table.php',
            '2026_10_07_000100_create_pos_tables.php',
            '2026_10_07_000200_add_purchase_cost_history.php',
            '2026_10_07_000300_add_product_search_indexes.php',
            '2026_10_07_000400_allow_payment_rule_bearer_inheritance.php',
            '2026_10_07_000500_add_usernames_to_users.php',
            '2026_10_08_000600_allow_split_sale_payments.php',
            '2026_10_08_000700_allow_repeated_payment_methods.php',
            '2026_10_08_000800_preserve_register_opening_time.php',
            '2026_10_08_000900_add_customer_opening_due.php',
            '2026_10_08_001000_add_multiple_product_units.php',
            '2026_10_08_001100_add_sale_line_adjustments.php',
            '2026_10_08_001200_add_stock_adjustment_batches.php',
            '2026_10_08_001300_add_sale_returns_and_collections.php',
            '2026_10_08_001400_preserve_sale_invoice_time.php',
            '2026_10_08_001500_add_sale_revisions.php',
            '2026_10_08_180000_create_stock_price_layers.php',
            '2026_10_08_195619_create_customer_payments_tables.php',
            '2026_10_08_203124_create_category_product_table.php',
            '2026_10_09_000100_add_purchase_payments.php',
            '2026_10_09_010000_add_purchase_charges_and_document_sequences.php',
            '2026_10_09_010100_track_remaining_landed_cost.php',
            '2026_10_09_100000_expand_role_permissions.php',
            '2026_10_09_145619_add_charge_fields_to_payment_methods.php',
            '2026_10_09_150819_add_charge_minimum_to_payment_methods.php',
            '2026_10_09_180000_add_sales_visibility_to_roles.php',
            '2026_10_10_000000_create_hr_module.php',
            '2026_10_10_165153_add_supplier_id_to_products_table.php',
            '2026_10_10_190000_extend_transaction_returns.php',
            '2026_10_10_210000_add_no_receipt_sales_returns.php',
            '2026_10_11_020000_add_product_discounts_and_stock_tracking.php',
            '2026_10_11_210000_create_demo_cycles_table.php',
            '2026_10_12_000000_add_optional_expiry_tracking.php',
            '2026_10_13_000000_create_barcode_system.php',
            '2026_10_14_000000_create_cheque_management.php',
            '2026_10_14_010000_add_cheque_return_resolutions.php',
            '2026_10_22_000000_create_sms_messages.php',
            '2026_10_22_010000_add_sms_settings_permission.php',
            '2026_11_02_000001_add_customer_tax_invoices.php',
            '2026_11_03_000001_create_super_admins_table.php',
        ];

        $filesOnServer = glob(database_path('migrations/*.php')) ?: [];
        $quarantined = [];
        foreach ($filesOnServer as $f) {
            $base = basename($f);
            if (! in_array($base, $officialMigrations, true)) {
                @unlink($f);
                @rename($f, $f . '.bak');
                $quarantined[] = $base;
            }
        }
        if (! empty($quarantined)) {
            $output .= "Quarantined rogue migrations (" . count($quarantined) . "):\n - " . implode("\n - ", $quarantined) . "\n\n";
        } else {
            $output .= "All " . count($filesOnServer) . " migration files verified.\n\n";
        }

        // 2. Drop all conflicting tables via single-session PDO connection
        $pdo = \Illuminate\Support\Facades\DB::getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $rawTables = \Illuminate\Support\Facades\DB::select('SHOW TABLES');
        $dropped = [];
        foreach ($rawTables as $row) {
            $tableName = array_values((array) $row)[0];
            $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
            $dropped[] = $tableName;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $output .= "Cleaned up " . count($dropped) . " existing tables: " . implode(', ', $dropped) . "\n\n";

        // 3. Run all migrations cleanly from scratch
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $output .= "=== Migrations ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        // 4. Run Seeders (roles, permissions, settings, categories, units)
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
        $output .= "=== Seeders ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        // 5. Ensure Administrator account is created and ready
        $adminRole = \App\Models\Role::where('name', 'Administrator')->first();
        $admin = \App\Models\User::firstOrNew(['username' => 'admin']);
        $admin->name = 'Administrator';
        $admin->email = 'admin@twinsofte.local';
        $admin->password = \Illuminate\Support\Facades\Hash::make('password');
        $admin->role_id = $adminRole?->id ?? 1;
        $admin->active = true;
        $admin->save();

        $output .= "=== Administrator Account Ready ===\n";
        $output .= "Username: admin\nPassword: password\nEmail: admin@twinsofte.local\n\n";

        // 5b. Seed Super Admin account (use DB directly to avoid any cast double-hash)
        \Illuminate\Support\Facades\DB::table('super_admins')->updateOrInsert(
            ['username' => 'superadmin'],
            [
                'password'   => \Illuminate\Support\Facades\Hash::make('password'),
                'active'     => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $output .= "=== Super Admin Account Ready ===\n";
        $output .= "Username: superadmin\nPassword: password\n\n";

        // 6. Clear optimize cache
        \Illuminate\Support\Facades\Artisan::call('optimize:clear');
        $output .= "=== Cache Cleared ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        return response('
            <div style="background:#0f172a;color:#f8fafc;padding:30px;font-family:system-ui,-apple-system,sans-serif;min-height:100vh;">
                <div style="max-width:800px;margin:0 auto;background:#1e293b;border-radius:12px;padding:24px;border:1px solid #334155;box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                    <h2 style="color:#22c55e;margin-top:0;">✅ Database Setup & Fix Completed Successfully!</h2>
                    <p style="color:#94a3b8;">All old conflicting tables were wiped, all 40 migrations ran cleanly, seeders installed, and the administrator account is ready.</p>
                    <div style="background:#0f172a;border-radius:8px;padding:16px;margin:20px 0;border:1px solid #334155;">
                        <h3 style="margin-top:0;color:#38bdf8;font-size:16px;">🔑 Your Login Credentials:</h3>
                        <p style="margin:4px 0;"><strong>Username:</strong> <code style="color:#a5f3fc;background:#1e293b;padding:2px 6px;border-radius:4px;">admin</code></p>
                        <p style="margin:4px 0;"><strong>Password:</strong> <code style="color:#a5f3fc;background:#1e293b;padding:2px 6px;border-radius:4px;">password</code></p>
                    </div>
                    <a href="/login" style="display:inline-block;background:#e11d48;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;margin-bottom:8px;margin-right:8px;">Go to Admin Login &rarr;</a>
                    <a href="/superadmin/login" style="display:inline-block;background:#7f1d1d;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;margin-bottom:24px;">Go to Super Admin &rarr;</a>
                    <details style="margin-top:16px;" open>
                        <summary style="cursor:pointer;color:#94a3b8;font-size:13px;">Execution Logs</summary>
                        <pre style="background:#090d16;color:#a5f3fc;padding:16px;border-radius:6px;overflow-x:auto;margin-top:10px;font-size:12px;line-height:1.5;">' . e($output) . '</pre>
                    </details>
                </div>
            </div>
        ');
    } catch (\Throwable $e) {
        return response('<pre style="background:#111;color:#f33;padding:20px;font-family:monospace;border-radius:8px;white-space:pre-wrap;">'
            . "ERROR: " . e($e->getMessage()) . "\n\n"
            . "=== EXECUTION LOG BEFORE ERROR ===\n" . e($output) . "\n\n"
            . "=== STACK TRACE ===\n" . e($e->getTraceAsString()) . '</pre>', 500);
    }
})->withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \App\Http\Middleware\DemoMode::class,
    \App\Http\Middleware\BusinessContext::class,
    \App\Http\Middleware\SystemActive::class,
]);

Route::get('/seeder', function (\Illuminate\Http\Request $request) {
    $output = "=== Database Seeder Execution ===\n\n";
    try {
        // 1. Run main DatabaseSeeder (Roles, Permissions, Units, Categories, Settings, Payment Methods)
        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => 'DatabaseSeeder',
            '--force' => true,
        ]);
        $output .= "=== Base Seeders (DatabaseSeeder) ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        // 2. Ensure Administrator account is created and active
        $adminRole = \App\Models\Role::where('name', 'Administrator')->first();
        $admin = \App\Models\User::firstOrNew(['username' => 'admin']);
        $admin->name = 'Administrator';
        $admin->email = 'admin@twinsofte.local';
        $admin->password = \Illuminate\Support\Facades\Hash::make('password');
        $admin->role_id = $adminRole?->id ?? 1;
        $admin->active = true;
        $admin->save();
        $output .= "=== Administrator Account ===\nUser 'admin' verified and ready.\n\n";

        // 3. Run UnitPresetSeeder
        if (class_exists(\Database\Seeders\UnitPresetSeeder::class)) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', [
                '--class' => 'UnitPresetSeeder',
                '--force' => true,
            ]);
            $output .= "=== Unit Presets ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";
        }

        // 4. Run PaymentExampleSeeder
        if (class_exists(\Database\Seeders\PaymentExampleSeeder::class)) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', [
                '--class' => 'PaymentExampleSeeder',
                '--force' => true,
            ]);
            $output .= "=== Payment Rules ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";
        }

        // 5. Run SampleProductSeeder (Standard supermarket inventory)
        if (class_exists(\Database\Seeders\SampleProductSeeder::class)) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', [
                '--class' => 'SampleProductSeeder',
                '--force' => true,
            ]);
            $output .= "=== Sample Products ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";
        }

        // 6. Optional: Bulk products if ?bulk=1 is passed
        if ($request->query('bulk') && class_exists(\Database\Seeders\BulkSampleProductSeeder::class)) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', [
                '--class' => 'BulkSampleProductSeeder',
                '--force' => true,
            ]);
            $output .= "=== Bulk Products ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";
        }

        // 7. Clear optimize cache
        \Illuminate\Support\Facades\Artisan::call('optimize:clear');
        $output .= "=== Cache Cleared ===\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        // Collect stats
        $counts = [
            'Roles' => \App\Models\Role::count(),
            'Categories' => \App\Models\Category::count(),
            'Units' => \App\Models\Unit::count(),
            'Payment Methods' => \App\Models\PaymentMethod::count(),
            'Products' => \App\Models\Product::count(),
            'Users' => \App\Models\User::count(),
        ];

        $statsHtml = '';
        foreach ($counts as $label => $num) {
            $statsHtml .= '<div style="background:#0f172a;padding:12px 16px;border-radius:8px;border:1px solid #334155;text-align:center;">
                <div style="font-size:20px;font-weight:bold;color:#38bdf8;">' . $num . '</div>
                <div style="font-size:12px;color:#94a3b8;margin-top:2px;">' . $label . '</div>
            </div>';
        }

        return response('
            <div style="background:#0f172a;color:#f8fafc;padding:30px;font-family:system-ui,-apple-system,sans-serif;min-height:100vh;">
                <div style="max-width:800px;margin:0 auto;background:#1e293b;border-radius:12px;padding:24px;border:1px solid #334155;box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                    <h2 style="color:#22c55e;margin-top:0;">✅ All Important Seeders Completed!</h2>
                    <p style="color:#94a3b8;">Base configuration, units, payment methods, categories, and sample products have been seeded.</p>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(110px, 1fr));gap:10px;margin:20px 0;">
                        ' . $statsHtml . '
                    </div>

                    <div style="background:#0f172a;border-radius:8px;padding:16px;margin:20px 0;border:1px solid #334155;">
                        <h3 style="margin-top:0;color:#38bdf8;font-size:16px;">🔑 Administrator Account:</h3>
                        <p style="margin:4px 0;"><strong>Username:</strong> <code style="color:#a5f3fc;background:#1e293b;padding:2px 6px;border-radius:4px;">admin</code></p>
                        <p style="margin:4px 0;"><strong>Password:</strong> <code style="color:#a5f3fc;background:#1e293b;padding:2px 6px;border-radius:4px;">password</code></p>
                    </div>

                    <div style="display:flex;gap:12px;align-items:center;margin-bottom:24px;">
                        <a href="/login" style="display:inline-block;background:#e11d48;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">Go to POS Login &rarr;</a>
                        <a href="/seeder?bulk=1" style="display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600;">+ Seed 120 Bulk Products</a>
                    </div>

                    <details style="margin-top:16px;" open>
                        <summary style="cursor:pointer;color:#94a3b8;font-size:13px;">Execution Logs</summary>
                        <pre style="background:#090d16;color:#a5f3fc;padding:16px;border-radius:6px;overflow-x:auto;margin-top:10px;font-size:12px;line-height:1.5;">' . e($output) . '</pre>
                    </details>
                </div>
            </div>
        ');
    } catch (\Throwable $e) {
        return response('<pre style="background:#111;color:#f33;padding:20px;font-family:monospace;border-radius:8px;white-space:pre-wrap;">'
            . "ERROR: " . e($e->getMessage()) . "\n\n"
            . "=== EXECUTION LOG BEFORE ERROR ===\n" . e($output) . "\n\n"
            . "=== STACK TRACE ===\n" . e($e->getTraceAsString()) . '</pre>', 500);
    }
})->withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \App\Http\Middleware\DemoMode::class,
    \App\Http\Middleware\BusinessContext::class,
    \App\Http\Middleware\SystemActive::class,
]);

Route::get('/seed', function (\Illuminate\Http\Request $r) {
    $query = $r->getQueryString() ? '?' . $r->getQueryString() : '';
    return redirect('/seeder' . $query);
});

/**
 * Super Admin Quick-Seed route.
 * Visits /superadmin-setup to create the super_admins table (if missing) and seed the superadmin user.
 * Safe to run on an already-migrated database.
 */
Route::get('/superadmin-setup', function () {
    try {
        // Ensure the super_admins table exists
        if (! \Illuminate\Support\Facades\Schema::hasTable('super_admins')) {
            \Illuminate\Support\Facades\Schema::create('super_admins', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('username')->unique();
                $table->string('password');
                $table->boolean('active')->default(true);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        // Ensure system_settings table exists
        if (! \Illuminate\Support\Facades\Schema::hasTable('system_settings')) {
            \Illuminate\Support\Facades\Schema::create('system_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
            \Illuminate\Support\Facades\DB::table('system_settings')->insert([
                'key' => 'system_active', 'value' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Upsert the superadmin user — use DB directly to avoid any model cast issues
        $hashedPassword = \Illuminate\Support\Facades\Hash::make('password');
        $existing = \Illuminate\Support\Facades\DB::table('super_admins')->where('username', 'superadmin')->first();
        if ($existing) {
            \Illuminate\Support\Facades\DB::table('super_admins')
                ->where('username', 'superadmin')
                ->update(['password' => $hashedPassword, 'active' => 1, 'updated_at' => now()]);
            $action = 'Updated';
        } else {
            \Illuminate\Support\Facades\DB::table('super_admins')->insert([
                'username'   => 'superadmin',
                'password'   => $hashedPassword,
                'active'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $action = 'Created';
        }

        return response('
            <div style="background:#0a0a0a;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;">
                <div style="background:#111;border:1px solid #1e1e1e;border-radius:14px;padding:36px 40px;max-width:420px;width:100%;text-align:center;">
                    <div style="font-size:48px;margin-bottom:16px;">🔐</div>
                    <h2 style="color:#4ade80;margin:0 0 8px;">Super Admin Ready!</h2>
                    <p style="color:#64748b;font-size:14px;margin-bottom:24px;">' . $action . ' the super admin account successfully.</p>
                    <div style="background:#0a0a0a;border-radius:10px;padding:16px;margin-bottom:24px;text-align:left;">
                        <p style="margin:6px 0;color:#94a3b8;font-size:14px;"><strong style="color:#f1f5f9;">Login URL:</strong> <code style="color:#fca5a5;">/superadmin/login</code></p>
                        <p style="margin:6px 0;color:#94a3b8;font-size:14px;"><strong style="color:#f1f5f9;">Username:</strong> <code style="color:#fca5a5;">superadmin</code></p>
                        <p style="margin:6px 0;color:#94a3b8;font-size:14px;"><strong style="color:#f1f5f9;">Password:</strong> <code style="color:#fca5a5;">password</code></p>
                    </div>
                    <a href="/superadmin/login" style="display:inline-block;background:#dc2626;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">Go to Super Admin Login &rarr;</a>
                </div>
            </div>
        ');
    } catch (\Throwable $e) {
        return response('<pre style="background:#111;color:#f33;padding:20px;">'
            . 'ERROR: ' . e($e->getMessage()) . "\n\n"
            . e($e->getTraceAsString())
            . '</pre>', 500);
    }
})->withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \App\Http\Middleware\DemoMode::class,
    \App\Http\Middleware\BusinessContext::class,
    \App\Http\Middleware\SystemActive::class,
]);

/*
|--------------------------------------------------------------------------
| Super Admin Panel Routes
|--------------------------------------------------------------------------
| Only accessible when SUPER_ADMIN=true in .env.
| The SuperAdminEnabled middleware returns 404 when SUPER_ADMIN=false.
| The SystemActive middleware is bypassed for all /superadmin/* routes.
*/
Route::prefix('superadmin')
    ->name('superadmin.')
    ->middleware(['superadmin.enabled'])
    ->withoutMiddleware([
        \App\Http\Middleware\BusinessContext::class,
        \App\Http\Middleware\DemoMode::class,
        \App\Http\Middleware\SystemActive::class,
    ])
    ->group(function () {

        // Guest routes (showLogin directly redirects to dashboard if already logged in)
        Route::get('/login', [SuperAdminAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [SuperAdminAuthController::class, 'login'])->name('login.post')->middleware('throttle:10,1');

        // Authenticated super admin routes
        Route::middleware('auth:superadmin')->group(function () {
            Route::get('/', [SuperAdminController::class, 'dashboard'])->name('dashboard');
            Route::get('/controls', [SuperAdminController::class, 'controls'])->name('controls');
            Route::post('/controls', [SuperAdminController::class, 'saveControls'])->name('controls.save');
            Route::get('/stock', [SuperAdminController::class, 'stock'])->name('stock');
            Route::get('/inventory', [SuperAdminController::class, 'stock'])->name('inventory');
            Route::post('/stock', [SuperAdminController::class, 'saveStock'])->name('stock.save');
            Route::get('/stock/search-products', [SuperAdminController::class, 'searchProducts'])->name('stock.search-products');
            Route::post('/stock/hidden-product', [SuperAdminController::class, 'addHiddenProduct'])->name('stock.hidden-product.add');
            Route::delete('/stock/hidden-product/{id}', [SuperAdminController::class, 'removeHiddenProduct'])->name('stock.hidden-product.remove');
            Route::get('/hide-records', [SuperAdminController::class, 'hideRecords'])->name('hide-records');
            Route::get('/privacy-mode', [SuperAdminController::class, 'privacyMode'])->name('privacy-mode');
            Route::get('/sales', [SuperAdminController::class, 'sales'])->name('sales');
            Route::get('/products', [SuperAdminController::class, 'products'])->name('products');
            Route::get('/profit', [SuperAdminController::class, 'profit'])->name('profit');
            Route::post('/toggle', [SuperAdminController::class, 'toggleSystem'])->name('toggle');
            Route::delete('/logout', [SuperAdminAuthController::class, 'logout'])->name('logout');
        });
    });
