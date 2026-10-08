# Source file manifest

The workspace was empty before this project; all listed files were created during implementation. Dependencies, build artifacts, runtime files and private credentials are omitted.

- `.editorconfig`
- `.env.example`
- `.gitattributes`
- `.gitignore`
- `.htaccess`
- `.phpunit.result.cache`
- `.prettierrc.json`
- `README.md`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/RegisterController.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/Controllers/ResourceController.php`
- `app/Http/Controllers/SaleController.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/StockController.php`
- `app/Http/Middleware/BusinessContext.php`
- `app/Http/Middleware/PermissionMiddleware.php`
- `app/Http/Requests/CheckoutRequest.php`
- `app/Http/Requests/LoginRequest.php`
- `app/Http/Requests/ProfileRequest.php`
- `app/Http/Requests/PurchaseRequest.php`
- `app/Http/Requests/RegisterRequest.php`
- `app/Http/Requests/ReportRequest.php`
- `app/Http/Requests/ResourceIndexRequest.php`
- `app/Http/Requests/ResourceRequest.php`
- `app/Http/Requests/SaleEditRequest.php`
- `app/Http/Requests/SettingsRequest.php`
- `app/Http/Requests/StockAdjustmentRequest.php`
- `app/Http/Requests/VoidRequest.php`
- `app/Models/AuditLog.php`
- `app/Models/Category.php`
- `app/Models/Customer.php`
- `app/Models/Expense.php`
- `app/Models/ExpenseCategory.php`
- `app/Models/PaymentChargeRule.php`
- `app/Models/PaymentMethod.php`
- `app/Models/Permission.php`
- `app/Models/Product.php`
- `app/Models/Purchase.php`
- `app/Models/PurchaseItem.php`
- `app/Models/Register.php`
- `app/Models/RegisterMovement.php`
- `app/Models/Role.php`
- `app/Models/Sale.php`
- `app/Models/SaleItem.php`
- `app/Models/SalePayment.php`
- `app/Models/Setting.php`
- `app/Models/StockMovement.php`
- `app/Models/Supplier.php`
- `app/Models/Unit.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/PaymentChargeService.php`
- `app/Services/ProfitLossService.php`
- `app/Services/PurchaseService.php`
- `app/Services/RegisterService.php`
- `app/Services/ReportService.php`
- `app/Services/ResourceService.php`
- `app/Services/SaleService.php`
- `app/Services/SettingsService.php`
- `app/Services/StockService.php`
- `app/Support/Audit.php`
- `app/Support/Money.php`
- `app/Support/Navigation.php`
- `app/Support/Resources.php`
- `artisan`
- `bootstrap/app.php`
- `bootstrap/providers.php`
- `composer.json`
- `composer.lock`
- `config/app.php`
- `config/auth.php`
- `config/cache.php`
- `config/database.php`
- `config/filesystems.php`
- `config/logging.php`
- `config/mail.php`
- `config/pos.php`
- `config/queue.php`
- `config/services.php`
- `config/session.php`
- `database/.gitignore`
- `database/factories/UserFactory.php`
- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/migrations/0001_01_01_000001_create_cache_table.php`
- `database/migrations/0001_01_01_000002_create_jobs_table.php`
- `database/migrations/2026_10_07_000100_create_pos_tables.php`
- `database/migrations/2026_10_07_000200_add_purchase_cost_history.php`
- `database/migrations/2026_10_07_000300_add_product_search_indexes.php`
- `database/migrations/2026_10_07_000400_allow_payment_rule_bearer_inheritance.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/PaymentExampleSeeder.php`
- `docs/DELIVERY.md`
- `docs/PERMISSIONS.md`
- `docs/SCHEMA.md`
- `docs/SETTINGS.md`
- `docs/routes.json`
- `docs/schema.sql`
- `docs/screenshots/dashboard.jpg`
- `docs/screenshots/long-receipt.jpg`
- `docs/screenshots/receipt-checkout.jpg`
- `package-lock.json`
- `package.json`
- `phpunit.xml`
- `public/.htaccess`
- `public/favicon.ico`
- `public/index.php`
- `public/robots.txt`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/js/pos.js`
- `resources/js/purchase.js`
- `resources/views/auth/login.blade.php`
- `resources/views/auth/profile.blade.php`
- `resources/views/components/icon.blade.php`
- `resources/views/crud/form.blade.php`
- `resources/views/crud/index.blade.php`
- `resources/views/crud/show.blade.php`
- `resources/views/crud/stock.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/pos/index.blade.php`
- `resources/views/purchases/form.blade.php`
- `resources/views/purchases/index.blade.php`
- `resources/views/purchases/show.blade.php`
- `resources/views/register/index.blade.php`
- `resources/views/register/show.blade.php`
- `resources/views/reports/index.blade.php`
- `resources/views/reports/profit.blade.php`
- `resources/views/reports/table.blade.php`
- `resources/views/sales/index.blade.php`
- `resources/views/sales/receipt.blade.php`
- `resources/views/sales/show.blade.php`
- `resources/views/settings/form.blade.php`
- `resources/views/settings/index.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Feature/ExampleTest.php`
- `tests/Feature/PosWorkflowTest.php`
- `tests/Support/browser-bootstrap.php`
- `tests/Support/fixture-logo.png`
- `tests/TestCase.php`
- `tests/Unit/MoneyTest.php`
- `vite.config.js`

Added in username-login update:

- `database/migrations/2026_10_07_000500_add_usernames_to_users.php`
- `tests/Feature/AuthenticationTest.php`
- `docs/screenshots/login.jpg`

Added in POS/split-payment update:

- `app/Services/SplitPaymentService.php`
- `database/migrations/2026_10_08_000600_allow_split_sale_payments.php`
- `tests/Feature/SplitPaymentTest.php`
- `docs/POS-SPLIT-PAYMENTS.md`
- `docs/screenshots/pos-workspace.jpg`
- `docs/screenshots/split-payment.jpg`
- `docs/screenshots/split-receipt.jpg`
- `docs/screenshots/three-method-receipt.jpg`

Added in the register and repeated-payment correction:

- `database/migrations/2026_10_08_000700_allow_repeated_payment_methods.php`
- `database/migrations/2026_10_08_000800_preserve_register_opening_time.php`
- `database/seeders/SampleProductSeeder.php`
- `resources/js/register.js`
- `resources/views/register/partials/dialogs.blade.php`
- `resources/views/register/partials/keypad.blade.php`
- `resources/views/register/partials/summary.blade.php`
- `tests/Feature/RegisterCheckoutTest.php`
- `docs/screenshots/pos-right-cart.jpg`
- `docs/screenshots/register-closing.jpg`

Added for the POS customer popup:

- `app/Http/Requests/PosCustomerRequest.php`
- `tests/Feature/PosCustomerTest.php`
- `docs/screenshots/add-customer.jpg`

Added for customer opening dues and redesigned views:

- `database/migrations/2026_10_08_000900_add_customer_opening_due.php`
- `resources/views/customers/form.blade.php`
- `resources/views/customers/index.blade.php`
- `resources/views/customers/show.blade.php`
- `tests/Feature/CustomerDueTest.php`
- `docs/screenshots/customer-form.jpg`
- `docs/screenshots/customer-profile.jpg`
- `docs/screenshots/customers-directory.jpg`
- `docs/screenshots/pos-customer-due.jpg`

Added for product multiple units:

- `app/Models/ProductUnit.php`
- `app/Models/UnitPreset.php`
- `app/Models/UnitPresetConversion.php`
- `app/Services/ProductUnitService.php`
- `database/migrations/2026_10_08_001000_add_multiple_product_units.php`
- `database/seeders/UnitPresetSeeder.php`
- `resources/js/product-units.js`
- `resources/views/products/form.blade.php`
- `resources/views/products/index.blade.php`
- `resources/views/products/show.blade.php`
- `resources/views/products/partials/conversions.blade.php`
- `resources/views/products/presets/form.blade.php`
- `resources/views/products/presets/index.blade.php`
- `resources/views/products/presets/show.blade.php`
- `tests/Feature/ProductUnitsTest.php`
- `docs/PRODUCT-UNITS.md`
- `docs/screenshots/product-form-units.jpg`
- `docs/screenshots/product-multiple-units.jpg`
- `docs/screenshots/unit-presets.jpg`
- `docs/screenshots/products-catalogue.jpg`
- `docs/screenshots/multiple-unit-receipt.jpg`

Added for compact cart editing and saved drafts:

- `app/Services/SaleLineService.php`
- `database/migrations/2026_10_08_001100_add_sale_line_adjustments.php`
- `resources/js/pos-draft.js`
- `resources/views/pos/partials/line-editor.blade.php`
- `resources/views/pos/partials/bill-discount.blade.php`
- `tests/Feature/SaleLineTest.php`
- `tests/pos-draft.test.mjs`
- `docs/POS-CART.md`
- `docs/screenshots/pos-compact-cart.jpg`
- `docs/screenshots/pos-line-editor.jpg`
- `docs/screenshots/pos-bill-discount.jpg`
- `docs/screenshots/pos-adjusted-receipt.jpg`

Added for grouped navigation and batch stock adjustments:

- `app/Support/Sidebar.php`
- `resources/views/layouts/sidebar-links.blade.php`
- `app/Models/StockAdjustment.php`
- `app/Models/StockAdjustmentItem.php`
- `app/Services/StockAdjustmentService.php`
- `app/Http/Controllers/StockBatchController.php`
- `app/Http/Requests/StockBatchRequest.php`
- `database/migrations/2026_10_08_001200_add_stock_adjustment_batches.php`
- `resources/views/stock-adjustments/index.blade.php`
- `resources/views/stock-adjustments/form.blade.php`
- `resources/views/stock-adjustments/show.blade.php`
- `resources/js/stock-adjustments.js`
- `tests/Feature/StockBatchTest.php`
- `docs/STOCK-ADJUSTMENTS.md`
- `docs/screenshots/stock-adjustment-form.jpg`
- `docs/screenshots/stock-adjustment-details.jpg`
- `docs/screenshots/report-sublinks.jpg`

Added in the payment amount focus update:

- `docs/screenshots/payment-autofocus.jpg`

Added in the sales workflow update:

- `app/Http/Requests/SaleReturnRequest.php`
- `app/Http/Requests/SaleCollectionRequest.php`
- `app/Models/SaleReturn.php`
- `app/Models/SaleReturnItem.php`
- `app/Models/SaleCollection.php`
- `app/Services/SaleAftercareService.php`
- `database/migrations/2026_10_08_001300_add_sale_returns_and_collections.php`
- `database/migrations/2026_10_08_001400_preserve_sale_invoice_time.php`
- `resources/js/sales.js`
- `resources/views/sales/partials/actions.blade.php`
- `resources/views/sales/partials/dialogs.blade.php`
- `tests/Feature/SaleAftercareTest.php`
- `docs/SALES-WORKFLOW.md`
- `docs/screenshots/sales-directory.jpg`
- `docs/screenshots/sale-details.jpg`
- `docs/screenshots/sale-return.jpg`
- `docs/screenshots/sale-due-payment.jpg`

- `app/Http/Requests/SaleRevisionRequest.php`
- `app/Models/SaleRevision.php`
- `app/Services/SaleRevisionService.php`
- `database/migrations/2026_10_08_001500_add_sale_revisions.php`
- `tests/Feature/SaleRevisionTest.php`
- `docs/screenshots/sales-actions-row.jpg`
- `docs/screenshots/sale-pos-editor.jpg`
- `docs/screenshots/sale-edit-payment.jpg`
