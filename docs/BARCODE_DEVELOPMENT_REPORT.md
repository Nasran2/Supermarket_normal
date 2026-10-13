# Barcode development report

Implemented the optional Barcode Labels module in the existing Twinsofte Laravel app. The local `.env` already contained `BARCODE=true`; the new migration has been applied and Laravel caches cleared. Existing product barcodes and stock records were preserved. The local MySQL database has one default preset and three example layouts.

## Delivered behavior

- Server feature flag controls every barcode action, permission, product field, search path, scanner preference and print entry point.
- Product format/validation and locked configurable generation support CODE128, EAN13, EAN8 and UPCA.
- Individual and bulk printing support exact price/unit/lot choices, independent quantities, purchase suggestions, explicit stock suggestions and confirmed generation of missing codes.
- Shared preview/print rendering uses mm dimensions, SVG quiet zones, text fitting, stock-limit validation, fixed-sheet pagination and partial final rows.
- Preset CRUD, duplication, single default, skipped positions, orientation, calibration offsets and test labels are included.
- Print history retains configuration and label-data snapshots for reproducible reprints.

## Architecture and integration

| Area | Implementation |
| --- | --- |
| Config and environment | `config/features.php` adds barcode with default false. Application logic uses `config('features.barcode')`; `.env.example` documents BARCODE=false. `config/barcode.php` separates global settings from layouts. |
| Middleware | `EnsureBarcodeFeatureEnabled`, registered as `barcode-feature`, returns 404 when disabled. All module routes also require authenticated sessions and action authorization. |
| Migration | `2026_10_13_000000_create_barcode_system.php` adds product format, presets, print jobs, lock row, permissions and three presets. It adapts any existing demo baseline without deleting barcodes. |
| Existing database | Reuses nullable UNIQUE `products.barcode`, `settings`, permission/pivot tables and `document_sequences`. Adds `products.barcode_type` default CODE128. |
| New tables | `barcode_label_presets`: name, single default, configuration JSON, creator/updater, timestamps. `barcode_print_jobs`: creator, preset name, configuration JSON, compact label snapshot JSON, total labels, timestamps. |
| Models | `BarcodeLabelPreset`, `BarcodePrintJob`; Product stores format and removes barcode data from serialized responses when disabled. User permission checks enforce the feature flag even for Administrator. |
| Controllers | `BarcodeController` orchestrates selection/search/generation, preview, jobs, settings, history and calibration. `BarcodePresetController` handles preset pages and operations. |
| Form Requests | `BarcodePrintRequest`, `BarcodePresetRequest`, `BarcodeSettingsRequest`; existing product/settings requests respect feature state and barcode edit permissions. |
| Services | `BarcodeService`: validation, collision checks, locked generation, guarded assignment, SVG rendering. `BarcodePresetService`: decimal normalization, bounds, fitting, default locking and pagination. `BarcodePrintService`: existing unit prices, price/lot choices, quantities, purchase suggestions and snapshot resolution. |
| Permissions | barcode.view, barcode.print, barcode.print_bulk, barcode.manage_presets, barcode.manage_settings, barcode.generate, barcode.edit_product_barcode, barcode.view_history. Hidden assignments are retained while disabled. |
| Preset structure | Full physical layout, visibility, alignment, fonts/bold, overflow, barcode type/height, custom/internal text, orientation, skipped labels and offsets. Canonical decimal mm strings in JSON; BigDecimal computes layout bounds and row capacity. |
| Product changes | Separate SKU/Barcode fields; generate control; optional/required validation; exact format per product; default generation preserves manual values; quick Print Label actions. Disabled edits preserve historical codes. |
| Purchase changes | Saved active purchase offers Print Labels for Purchased Items with received base-unit quantities and original price/lot when still available. Staff must choose if original option is unavailable. |
| POS changes | Scanning is conditional on the feature and scanner preference. Exact scan still uses existing stock/price/expiry logic; no duplicated cart or allocation code. Disabled matching ignores barcode even under old search preferences. |
| Other searches | Category, adjustment, return, expiry and purchase selection remove barcode matching/display when disabled. Historical barcode audit fields/actions are hidden while disabled. |
| Expiry | Exact remaining stock lot choices, expiry/batch visibility and fonts only with EXPIRE enabled. Disabling hides controls/output while preserving lot, preset and history data. |
| Security | Server resolves prices and lots from stock data; clients cannot invent a price key. Separate bulk/print/generation/edit permissions. Product costs never enter label APIs or snapshots. Secret Code is plain custom/internal text. |
| Transactions | Sequences, assignments, jobs, settings and preset/default mutations are transactional. Preset default operations use one permanent lock row. Existing product uniqueness remains database-enforced. |
| Blade pages/components | Index, setup partial, shared sheet renderer, standalone print page, settings, preset list/editor and history. Existing Twinsofte light cards, emerald buttons and responsive table wrapping are retained. |
| JavaScript | Debounced search/preview, persistent selected rows, individual quantities, repeated price/lot rows, bulk missing-code generation, preset create/update, confirmation, shared fit checking and orientation changes. |
| Print CSS | Scoped physical-mm rules and @page are emitted in the shared sheet renderer; @media print hides toolbar and prints labels only. SVG encoding is from maintained Picqer v3.3.0. |
| Demo compatibility | Settings/preset changes respect existing demo read-only rules. Generated jobs participate in disposable demo-data resets; new migration updates existing baseline schema. |

## Routes

All routes are inside the app's authenticated group plus `barcode-feature`.

| Verb | Path | Access |
| --- | --- | --- |
| GET | /barcode; /barcodes/print | barcode.view |
| GET | /barcode/products | barcode.view |
| POST | /barcode/generate | barcode.generate |
| POST | /barcode/products/{product} | generate for generated values; edit_product_barcode for manual values/replacements |
| POST | /barcode/preview | barcode.view; print_bulk for multiple products |
| POST | /barcode/jobs | barcode.print; print_bulk for multiple products |
| GET | /barcode/jobs/{job} | barcode.print plus owner/history access; print_bulk for bulk jobs |
| GET | /barcode/history | barcode.view_history |
| GET | /barcode/calibration | barcode.print |
| GET, PUT | /settings/barcode | barcode.manage_settings |
| GET, POST | /barcode/presets | barcode.manage_presets |
| GET | /barcode/presets/create; /barcode/presets/{preset}/edit | barcode.manage_presets |
| PUT, DELETE | /barcode/presets/{preset} | barcode.manage_presets |
| POST | /barcode/presets/{preset}/duplicate; /barcode/presets/{preset}/default | barcode.manage_presets |

## Verification results

| Check | Result |
| --- | --- |
| Dedicated barcode feature suite | **20 passed, 210 assertions.** Search-required product results by name/SKU/barcode, flag/permission state, data preservation, all disabled mutations, named duplicates, valid generation, invalid EAN, quantities/partial rows, real-price keys, exact lots, stock limits, required codes, custom dimensions, presets/default edits/snapshots, unit conversion, missing codes and scannability bounds. |
| Existing JavaScript tests | **26 passed, 0 failed.** POS scanning and price-option behavior plus existing cart, purchase and adjustment tests. |
| Browser printing suite | Passed product generation, explicit price selection, 5-label individual job, 10 @ 130 plus 15 @ 140, 35-label bulk job, preset create/edit/default retention, invalid paper-width rejection and physical preview/print equality. |
| Browser stock suite | Passed exact February expiry/batch labels, purchase suggestions 24/12, single-stock-option scan adding immediately, and multiple-price scan opening the existing unified selector. |
| Browser toggle suite | Passed BARCODE=false product/sidebar/roles/settings/routes/POS, BARCODE=true restoration against the same database, and EXPIRE=false hiding date/batch controls. |
| Responsive checks | 1920, 1366, 1024 and 768px. No page-wide overflow; wide product table scrolls within its card. Screenshots visually inspected. |
| Console/server errors | None in the exercised barcode browser workflows. |
| Print export | Exact five labels, three rows, one page; measured **101.94 × 81.11 mm** for configured 102 × 81 mm (browser point quantization). Preview/print label bounds match 49 × 25 mm. Rendered PDF visually inspected. |
| Independent decoder | All five rasterized printed codes decoded as the expected CODE128 value using ZXing. Apple's Vision also decoded the barcode image. |
| Build/templates/style | Vite production build and Blade compilation passed; Laravel Pint applied; source diff whitespace checks passed. |
| Local database deployment | Migration applied successfully to MySQL; existing BARCODE=true retained; configuration/cache cleared; exactly one default preset verified. |
| Full PHP regression suite | **401 passed, 46 failed, 8,802 assertions.** An exported unchanged HEAD baseline reproduced the same **46 failures** with 382 passing tests. Comparing failing test identities found **no added regression failures**. |

The full pre-existing suite is not green. Existing failures span authentication fixture uniqueness, payment-fee/checkout expectations, seeder permission assumptions and related tests. They also occur before this change; their unrelated behavior was not changed to make the suite green. The barcode suite and browser checks pass.

Physical scanner/printer feed, driver margins and actual-size scaling still need calibration on the store's hardware. Browser/PDF geometry and independent decoding verify the generated output, not the connected printer's mechanics.

## Search-only label selection

The Barcode Labels catalogue now stays hidden until Search contains a name, SKU or barcode. Empty or whitespace searches return no products; filters alone do not list the catalogue. Selected products and their quantities remain visible across searches and after clearing Search. Stale search responses are ignored. Product/purchase Print Label links still supply their requested rows. The updated feature suite and browser printing flow passed, including three searched products producing 35 labels.

## Deployment instructions

```dotenv
BARCODE=true
```

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

Rebuild configuration with `php artisan config:cache` if that is the server's normal production workflow. `npm ci && npm run build` is required only when deploying without the committed built assets or after editing source browser assets. The local migration/build/cache clearing are already done. Grant barcode permissions to existing Manager/Cashier roles as appropriate; Administrator gets access from the existing permission model.

## Files created

- `app/Http/Controllers/BarcodeController.php`
- `app/Http/Controllers/BarcodePresetController.php`
- `app/Http/Middleware/EnsureBarcodeFeatureEnabled.php`
- `app/Http/Requests/BarcodePresetRequest.php`
- `app/Http/Requests/BarcodePrintRequest.php`
- `app/Http/Requests/BarcodeSettingsRequest.php`
- `app/Models/BarcodeLabelPreset.php`
- `app/Models/BarcodePrintJob.php`
- `app/Services/BarcodePresetService.php`
- `app/Services/BarcodePrintService.php`
- `app/Services/BarcodeService.php`
- `config/barcode.php`
- `database/migrations/2026_10_13_000000_create_barcode_system.php`
- `docs/BARCODE_DEVELOPMENT_REPORT.md`
- `docs/BARCODE_SYSTEM.md`
- `resources/js/barcode-layout.js`
- `resources/js/barcode-preset.js`
- `resources/js/barcode-print.js`
- `resources/js/barcode-product.js`
- `resources/js/barcode.js`
- `resources/views/barcode/history.blade.php`
- `resources/views/barcode/index.blade.php`
- `resources/views/barcode/preset-edit.blade.php`
- `resources/views/barcode/presets.blade.php`
- `resources/views/barcode/print.blade.php`
- `resources/views/barcode/settings.blade.php`
- `resources/views/barcode/setup.blade.php`
- `resources/views/barcode/sheet.blade.php`
- `tests/Feature/BarcodeSystemTest.php`
- `tests/Support/barcode-browser-bootstrap.php`
- `tests/barcode-browser.test.cjs`
- `tests/barcode-flags-browser.test.cjs`
- `tests/barcode-stock-browser.test.cjs`

## Files modified

- `README.md`
- `.env.example`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/ResourceController.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/StockBatchController.php`
- `app/Http/Controllers/TransactionReturnController.php`
- `app/Http/Requests/ResourceRequest.php`
- `app/Http/Requests/SettingsRequest.php`
- `app/Models/Product.php`
- `app/Models/User.php`
- `app/Services/DemoDataService.php`
- `app/Services/ExpiryReportService.php`
- `app/Services/ReportService.php`
- `app/Services/ResourceService.php`
- `app/Support/AuditPresentation.php`
- `app/Support/Demo.php`
- `app/Support/Permissions.php`
- `app/Support/Resources.php`
- `app/Support/Sidebar.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `config/features.php`
- `resources/css/app.css`
- `resources/js/pos.js`
- `resources/js/purchase.js`
- `resources/js/stock-adjustments.js`
- `resources/views/categories/show.blade.php`
- `resources/views/expiry/index.blade.php`
- `resources/views/pos/index.blade.php`
- `resources/views/products/form.blade.php`
- `resources/views/products/index.blade.php`
- `resources/views/products/show.blade.php`
- `resources/views/purchases/form.blade.php`
- `resources/views/purchases/show.blade.php`
- `resources/views/returns/create.blade.php`
- `resources/views/returns/no-receipt.blade.php`
- `resources/views/settings/form.blade.php`
- `resources/views/settings/index.blade.php`
- `resources/views/stock-adjustments/form.blade.php`
- `routes/web.php`
- `vite.config.js`

Generated deployment files also changed under `public/build/` and `vendor/composer/`, and `vendor/picqer/php-barcode-generator/` was added because this repository commits built assets and dependencies. `package-lock.json` and unrelated packages were not changed. The report and operational guide are the documentation deliverables; browser screenshots/PDF evidence live in the ignored `tests/.browser-artifacts/barcode/` directory.
