# Stock prices, actual FIFO costs and management UI

Implemented on 8 October 2026 for the existing Laravel supermarket application. The emerald/light palette, existing categories, SKU generation, multi-unit conversions, payment rules, customer dues, returns, register flows and POS invoice editor are retained.

## What staff see

- Product creation starts with the global primary unit and one opening row. Enter quantity, cost and selling price; add more rows for differently priced stock. A zero opening quantity is permitted on the first row. Additional rows require a positive quantity.
- Products display available selling prices, primary-unit stock, stock history and clickable sales invoices. Privileged users also see each stock row’s cost, margin, source, original/remaining quantity, received date and valuation, with available/depleted/all filters.
- POS adds directly when all available stock has the same selling price, including stock from multiple deliveries. Different available selling prices open a centered popup with one choice per price and its combined available quantity. Use the numbered shortcuts, arrow keys, Enter or Escape. Checkout consumes deliveries at the selected price by FIFO and keeps each delivery's actual cost.
- The price popup labels each selling price, available primary-unit quantity and Add to cart action. Depleted choices are excluded on both server and browser; choices are sorted by price and received date. Stock adjustment cost and selling-price inputs are directly visible beside quantity in each row. Switching from Add to Remove shows the selected delivery’s read-only prices, while switching back restores unsaved input values.
- Purchases record a cost and selling price for each incoming row. Existing stock prices are untouched. Name, SKU and barcode search work. Selling below cost displays a warning. Inline supplier creation retains validation errors instead of closing the popup on failure.
- Stock adjustments accept multiple products, select an existing stock row for removal or compatible incoming stock, and can create a new row at an authorized cost/selling price. Existing rows keep their original costs.
- User, expense, unit, supplier, category, payment setup and role add/edit/details pages share clearer cards, labels, larger inputs and responsive spacing. Roles have readable actions, search, selection counts and module/global selection controls. No role grants were saved during browser UI testing.

## Database and migration

The 9 October follow-up passed all 199 PHP tests (1,956 assertions), 11 targeted JavaScript tests and the production asset build. Browser QA in the isolated database verified a two-price popup (130 / 20 pcs and 140 / 15 pcs), adding the selected 140 price, automatic addition at the single remaining 150 price, and inline adjustment price/cost edits with draft preservation across mode changes. Preview screenshots: [inline adjustment prices](screenshots/stock-adjustment-inline-prices.png), [available-price popup](screenshots/pos-available-price-popup.png), [single-price addition](screenshots/pos-single-stock-price.png).

The final same-price correction passed 29 targeted PHP tests (309 assertions), 12 JavaScript tests and the production build. Browser QA in the isolated database confirmed that Ceylon Tea with 44 pcs and 10 pcs both priced at 490 adds automatically. A quantity of 45 was accepted by the checkout quote, spanning both deliveries, and survived refresh. Different prices still opened the chooser with their available quantities, and selecting 140 added the correct cart price. No sale was submitted during this browser check. Older drafts with an explicit delivery selection remain compatible. [Same-price automatic addition](screenshots/pos-same-price-auto-add.png).

Migration: `2026_10_08_180000_create_stock_price_layers.php`.

| Table | Change |
| --- | --- |
| `units` | Nullable unique `default_slot`: one default has value 1; other units have NULL. |
| `product_stock_layers` | Product/primary unit, source type/id/reference, purchase item link, original/remaining primary quantity, cost, selling price, received date, creator and status. FIFO and source/valuation indexes. |
| `sale_stock_allocations` | Exact stock rows consumed by a sale item, quantity, returned quantity, cost snapshot and allocated COGS. |
| `stock_layer_movements` | Links each stock movement to the stock rows and snapshots its quantity, cost and selling price. |
| `sale_items` | Nullable selected `stock_price` and actual `cogs_total`. |
| `purchase_items` | Nullable entered `selling_price` and primary-unit `base_selling_price`. |

Money uses DECIMAL(15,2), quantities DECIMAL(18,3), and server calculations use Brick BigDecimal/Money helpers. Floating-point browser calculations are previews only. Foreign keys protect product/unit/stock references and retained stock history.

The migration creates one `MIGRATED_STOCK` row for each existing positive balance using its recorded cost and price. It does **not** replay movements, change product quantities or recalculate old invoices. Old sale COGS remains historical. Missing historical purchase breakdowns cannot be reconstructed from a single existing balance; migrated rows explicitly identify that source. A compatibility bridge supports older integrations with positive product stock but no stock rows. Returns of older invoices establish a return source using their historical item snapshots.

The local migration is applied. Reconciliation found 16 product balances and 16 imported stock rows, both totaling 818.000, with no per-product mismatch or negative stock row. Manual transaction testing used the separate `twinsofte_supermarket_ui` database. Keep a database backup and pause stock mutations when applying this migration to a different/live deployment. Rolling the migration back removes allocation history and is not a financial undo operation.

## Relationships and services

New models: `ProductStockLayer`, `SaleStockAllocation`, `StockLayerMovement`. Product has stock layers; purchase items have received layers; sale items have allocations; stock movements have row details. Layers link back to their product, primary unit, incoming purchase item and allocation/movement history.

`StockLayerService` receives stock, aggregates selling-price choices, reserves and consumes FIFO within the selected price, restores original allocations, reverses unused adjustment sources and explicitly reprices remaining stock. Product and row locks plus transactions protect completion and adjustments. Cumulative reservations reject duplicate-line overselling; a selected price never borrows from another price. Quote hashes reject stale price/cost/stock changes. Existing checkout idempotency remains active. Actual concurrent-worker races were not separately load-tested.

`DefaultUnitService` selects an active unit transactionally and records the change in the audit log. Initially Piece (`pcs`) is selected without a hard-coded unit ID. Disabling/deleting the current default is blocked. Changing the default affects only newly opened product forms.

Updated `ResourceService`, `PurchaseService`, `SaleService`, `SaleRevisionService`, `SaleAftercareService`, `StockService`, `StockAdjustmentService`, `ProductUnitService` and `ReportService` connect existing workflows to stock rows. Purchase editing/voiding is blocked once any of its stock has been consumed. Unused deliveries can be reversed and replaced exactly. Older purchases without allocation data use stock adjustments for corrections.

## COGS, returns and reports

A sale consumes oldest received stock rows at the **selected selling price**. COGS is the sum of consumed primary quantities × their original costs; no weighted average or latest catalogue cost is substituted. Allocated cents reconcile to the final item cost. Partial-return rounding uses cumulative allocated costs so full returns reverse the exact original cents. Voids and returns restore original physical rows. Sale revisions restore previous allocations before validating and allocating their replacement.

Product price/cost fields are suggested defaults; editing them does not rewrite existing stock. Explicit remaining-stock repricing records an audit entry and retains sold-price/cost snapshots. Additional sale/purchase units convert to the same primary balance. Converted selling prices are derived from the selected stock price unless that product conversion has an explicit override.

Stock reports have product summary/stock-by-price views, product/category, cost/selling price, source reference, received date and stock status filters. Cost valuation sums remaining quantity × row cost; potential sales value sums row selling values. Sales reports show actual invoice COGS, gross profit and margin after returns for authorized users. Product-sales reports explicitly label their original line totals/COGS before invoice discounts and returns. Profit & Loss uses stored actual sale COGS less returned COGS, and retains existing fee/expense treatment. CSV exports use the same report calculations.

## Routes, controllers and permissions

New routes:

- `POST /units/{unit}/default` (`units.default`), requires `units.edit` and explicit confirmation.
- `PUT /stock-prices/{layer}` (`stock-prices.update`), requires `products.manage_prices` and explicit confirmation.

Existing product management, POS product/quote/complete, invoice edit quote/save, purchase create/update/void, stock adjustment and report routes now use these services. Controllers changed: `ResourceController`, `PosController`, `SaleController`, `PurchaseController`, `StockController`, `StockBatchController`, `ReportController`; new `StockPriceController` handles the two actions.

New permissions: `products.view_cost`, `products.manage_prices`, `purchases.manage_prices`. Existing Administrator/Manager roles receive them during migration. Cashiers receive no new financial access. POS JSON excludes cost/allocation data, product views aggregate their choices without cost rows, and profit/cost views require cost access. The dashboard also hides profit for users without cost access. Supplier create uses the existing supplier-to-purchase permission mapping.

## JavaScript and templates

`pos.js` adds price selection and distinct row identities; `pos-draft.js` preserves selected stock prices and restores only valid available choices. Existing new-sale persistence, line/bill discount dialogs, customer prompt and full selected payment amount continue to work. Invoice edits retain their reset-on-refresh behavior.

`purchase.js` handles incoming prices, precision, costs/totals and SKU/barcode search. `stock-adjustments.js` handles stock-row choices and permission-sensitive price controls. `module-editor.js` provides permission selection/search and opening-row add/remove/warnings. `app.js` loads the shared editor and missing icons. CSS defines shared module cards, responsive fields, stock tables and the price dialog. Production assets and Blade templates compile successfully.

## Verification

- Full PHP regression suite: **169 passed**, 1,587 assertions in the final run.
- New stock-price suite: **18 passed**, including all 12 requested acceptance cases plus duplicate purchases, safe purchase edit/void, exact adjustment reversal, rollback on fractional piece input, fractional return rounding and migration backfill.
- MySQL transaction/stock-focused suite: **46 passed**, 534 assertions in the disposable test database.
- POS draft JavaScript: **5 passed**, including persistence of separate selected stock prices.
- Production Vite build, Blade compilation, Pint and Git whitespace checks passed.
- Browser checks covered price choices, separate cart rows, full selected payment amount, successful checkout/draft clear, incoming purchase price, depleted choices, direct add, invoice links, stock/sales/P&L reports, role controls and desktop/mobile forms. Missing icon warnings found during inspection were fixed.

### Manual Bath Soap walkthrough (isolated UI database)

| Step | Result |
| --- | --- |
| Opening stock 20 @ cost100/sell130 + 15 @ cost110/sell140 | 35 pieces, two available prices. |
| Sell 2 at 130 (INV-000026) | 18 @130 + 15 @140; sale revenue260, COGS200. |
| Receive 10 @ cost120/sell150 (SOAP-PRICE-PURCHASE-QA) | 43 pieces, three prices, old prices unchanged. |
| Sell remaining 18 @130 and 15 @140 (INV-000027) | Revenue4,440, COGS3,450, gross profit990, margin22.30%. |
| Remaining stock | 10 @150; cost value1,200; potential sales value1,500. |
| Click product after depletion | Added immediately at150; price popup stayed closed. |
| Click invoice in movement history | Opened INV-000027 with the two correct item prices/quantities. |

Screenshots are saved under `docs/screenshots/`: `stock-price-choice.jpg`, `stock-price-product.jpg`, `management-user-editor.png`, `roles-permissions-editor.png`, `management-mobile.png`.

## Source inventory

New implementation source files:

- `app/Http/Controllers/StockPriceController.php`
- `app/Models/ProductStockLayer.php`
- `app/Models/SaleStockAllocation.php`
- `app/Models/StockLayerMovement.php`
- `app/Services/DefaultUnitService.php`
- `app/Services/StockLayerService.php`
- `database/migrations/2026_10_08_180000_create_stock_price_layers.php`
- `resources/js/module-editor.js`
- `resources/views/pos/partials/price-choice.blade.php`
- `resources/views/products/partials/opening-row.blade.php`
- `resources/views/products/partials/stock-prices.blade.php`
- `tests/Feature/StockPriceLayersTest.php`

Modified source files in the current working tree (includes earlier enhancements retained during this request):

- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/PurchaseController.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/Controllers/ResourceController.php`
- `app/Http/Controllers/SaleController.php`
- `app/Http/Controllers/StockBatchController.php`
- `app/Http/Controllers/StockController.php`
- `app/Http/Requests/CheckoutRequest.php`
- `app/Http/Requests/PurchaseRequest.php`
- `app/Http/Requests/ReportRequest.php`
- `app/Http/Requests/ResourceRequest.php`
- `app/Http/Requests/StockAdjustmentRequest.php`
- `app/Http/Requests/StockBatchRequest.php`
- `app/Models/Product.php`
- `app/Models/PurchaseItem.php`
- `app/Models/SaleItem.php`
- `app/Models/StockMovement.php`
- `app/Models/Unit.php`
- `app/Services/ProductUnitService.php`
- `app/Services/PurchaseService.php`
- `app/Services/ReportService.php`
- `app/Services/ResourceService.php`
- `app/Services/SaleAftercareService.php`
- `app/Services/SaleRevisionService.php`
- `app/Services/SaleService.php`
- `app/Services/StockAdjustmentService.php`
- `app/Services/StockService.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/SampleProductSeeder.php`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/js/pos-draft.js`
- `resources/js/pos.js`
- `resources/js/purchase.js`
- `resources/js/stock-adjustments.js`
- `resources/views/crud/form.blade.php`
- `resources/views/crud/index.blade.php`
- `resources/views/crud/show.blade.php`
- `resources/views/crud/stock.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/pos/index.blade.php`
- `resources/views/products/form.blade.php`
- `resources/views/products/index.blade.php`
- `resources/views/products/partials/stock-history.blade.php`
- `resources/views/products/show.blade.php`
- `resources/views/purchases/form.blade.php`
- `resources/views/purchases/show.blade.php`
- `resources/views/reports/table.blade.php`
- `resources/views/stock-adjustments/form.blade.php`
- `routes/web.php`
- `tests/Feature/DueCheckoutTest.php`
- `tests/Feature/PosWorkflowTest.php`
- `tests/Feature/SaleAftercareTest.php`
- `tests/Feature/SaleLineTest.php`
- `tests/Feature/SaleRevisionTest.php`
- `tests/Feature/StockBatchTest.php`

- `tests/pos-draft.test.mjs`

Documentation and generated production assets are updated alongside these sources. Earlier due-checkout and sales-workflow documentation/screenshots remain in the working tree.
