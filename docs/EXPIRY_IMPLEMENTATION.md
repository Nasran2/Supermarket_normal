# Optional batch expiry tracking — implementation and verification

Completed locally on 11 October 2026. The implementation extends the existing inventory layers and does not create a second stock balance.

## Delivered behavior

- `EXPIRE` is read once through `config/features.php`, with false as the code and example-environment default. Product expiry tracking also defaults to false.
- Global-off mode hides expiry controls, settings, reports, alerts, badges and receipt dates. Direct expiry routes return 404. Saved dates, stock quantities and historical allocations remain intact.
- Opening stock and purchases retain separate dates, prices, costs, batches and source references. Incoming date validation rejects malformed, already expired, or receiving-day purchase dates.
- The POS uses one combined selling-price/date selector. One available option adds automatically. Matching price/date options aggregate quantity while retaining separate cost allocations.
- ASK is the default. FEFO consumes earliest valid dates at one selected selling price and can split across dates at that same price. Different prices remain separate options.
- Explicit selected dates cannot borrow stock from another date. Multi-unit quantities consume the corresponding primary units. Checkout locks products/layers and revalidates inventory, dates and price.
- Expired and unknown-date batches are unsellable by default. A manager override requires both policy switches, its permission and an explicit confirmation. A date equal to the shop's current date is still valid for selling.
- Dashboard alerts, product physical/sellable/expired/unknown summaries, low-stock checks, report filters, all-page PDF/CSV export and optional receipt dates are connected to the same stock records.
- Verified customer returns use original stock allocations. Expired returns cannot be restocked as sellable inventory. Existing controlled write-off/supplier-return resolutions remain available.
- No-bill returns require a known packaging date for restocking by default. If unknown restock is explicitly enabled, unknown-date stock stays unsellable until identified.
- Purchase returns reduce only the original purchase stock layer. Expired stock write-offs remove actual remaining inventory and record its cost exactly once in Expenses and Profit & loss.
- Optional batch-number controls can be hidden without erasing existing batch numbers during corrections or purchase edits.

## Database and deployment

Migration: `database/migrations/2026_10_12_000000_add_optional_expiry_tracking.php`.

| Table | Additions |
|---|---|
| products | track_expiry boolean, default false |
| product_stock_layers | nullable DATE expires_at, batch_number, expiry_source, two lookup indexes |
| purchase_items | nullable DATE expires_at and batch_number |
| sale_stock_allocations | nullable DATE expires_at and batch_number snapshots |
| sale_return_items | nullable DATE expires_at and batch_number |
| expiry_writeoffs | unique confirmation token, unique reference, layer/expense/user references, quantity, actual cost, reason and timestamps |

No existing quantity is duplicated or assigned an invented expiry. Existing undated stock needs a packaging check and an authorized date correction before becoming sellable under expiry tracking. Known dated layers with completed sales cannot have their dates/batch identities rewritten; historical sale snapshots are preserved.

The migration also installs the new permissions and extends any existing demo baseline with the initially empty write-off table. It does not reset the operational database. Demo settings locks and the existing reset cycle continue to apply.

**Local status:** the additive migration was applied successfully to the project's configured database and Laravel caches were cleared. The project's existing `.env` already has `EXPIRE=true`; its value was not changed. No remote hosting deployment or Git push was performed.

For hosting, back up the database, deploy the application and matching built assets, then run:

```sh
php artisan migrate --force
php artisan optimize:clear
npm run build
```

Set `EXPIRE=true` where needed; rebuild configuration cache afterward if the hosting deployment uses it. Set `EXPIRE=false` and clear configuration cache to disable. Reload already-open POS tabs after changing the flag. Do not roll back the schema to disable the feature, because a schema rollback removes its stored columns/history.

## Services and controllers

| Component | Responsibility |
|---|---|
| Expiry support | Feature switch, product eligibility, shop-calendar comparisons, statuses and setting defaults |
| ExpiryService | Server-side incoming date/batch validation |
| StockLayerService | Combined choices, FEFO, explicit-date allocation, locks and historical snapshots |
| ResourceService | Product flag and opening-stock integration |
| PurchaseService | Purchase dates/batches, permission checks and preservation during disabled-feature edits |
| SaleService | Pass selected date and explicit override confirmation into allocation |
| StockReturnService / SalesReturnService | Exact original allocations; reject expired restock |
| NoReceiptSalesReturnService | Packaging dates, optional unknown restock, retained unverified-return cost basis |
| ExpiryReportService | Positive-quantity queries, all filters, eager related sources and cost-aware export rows |
| ExpiryWriteoffService | Locked remaining-stock write-off, actual inventory cost, unique confirmation token, expense and audit |
| ProfitLossService | Include expiry write-off in operating expenses once |
| ExpiryController | Report/PDF/CSV, authorized correction and confirmed write-off |
| DashboardController | Lower dashboard alert table and expired-batch count |
| DemoDataService | Include expiry write-offs in the existing demo lifecycle |

Expiry queries compare DATE columns directly for index use. PDF export passes total row count to the existing large-table renderer when required. CSV cells that could become spreadsheet formulas are escaped. Costs are excluded from cashier price-choice payloads. Report cost columns require both expiry and product cost permissions.

## Routes and permissions

| Method | Path | Access |
|---|---|---|
| GET | /reports/expiry | expiry.view |
| GET | /reports/expiry/pdf | expiry.view; cost columns separately restricted |
| GET | /reports/expiry/export | expiry.view; cost columns separately restricted |
| PUT | /expiry/{layer} | purchase.expiry_edit |
| POST | /expiry/{layer}/writeoff | expiry.writeoff plus quantity, reason and confirmation token |
| GET / PUT | /settings/expiry | expiry.manage_settings |

All expiry endpoints reject access with 404 when the feature is disabled. Normal product/purchase/checkout requests accept expiry metadata only when relevant. Existing transaction permissions continue to apply.

New permissions: `expiry.view`, `expiry.manage_settings`, `expiry.override_expired_sale`, `expiry.writeoff`, `expiry.view_cost`, `purchase.expiry_edit`. Initial equivalent management access is copied only when each permission is first introduced; later revocations are not restored. Expired-sale override has no legacy equivalent grant. Administrator access follows the existing administrator-role policy.

## UI and settings

Products have a Track expiry checkbox that requires Maintain stock. Opening and purchase rows show dates only for tracked products. Product details show dated stock and corrections. Stock adjustments and no-bill returns show relevant batch/date controls. Receipt dates use completed allocation snapshots and are hidden by default.

Defaults: new-product tracking off; selection ASK; alert 45 days; urgent 7 days; dashboard on/max 10; purchase date required; batch numbers on; POS date display on; receipt dates off; block expired sales on; manager override off; unknown-return restock off.

The expiry report supports soon/expired/all tabs and filters for date range, status, product, category, supplier, batch, source/purchase, search and remaining/depleted stock. A custom accessible confirmation dialog reviews the product, quantity, date and permitted cost before stock write-off. Mobile actions wrap, and report tables scroll within their container. Existing print rules hide controls.

## Automated verification

- Latest expiry-focused run: **37 passed, 171 assertions**.
- Screen-render regression test now explicitly expects the expiry settings route to be hidden when the feature is off; it passes alongside the expiry tests.
- Purchase charges/payment regression: **21 passed, 223 assertions**.
- JavaScript: **26 passed** using `node --test tests/*.test.mjs`.
- Full suite snapshot: **418 tests, 372 passed, 46 failed, 4,118 assertions**.
- Unchanged HEAD baseline in an isolated temporary source checkout: **382 tests, 336 passed, the same 46 failed**. Comparing JUnit testcase identities produced **zero new failures**.
- Existing full-suite failures are in authentication fixtures, payment/fee expectations and existing role/stock access expectations. They were not changed as part of this feature.
- After the full-suite snapshot, the batch-number preservation case was extended and a test for export rows beyond the first report page was added; the expiry suite was rerun successfully. Changes after that snapshot were limited to this preservation behavior, clearer FEFO instructional text, report mobile wrapping, a missing receipt-icon alias discovered during QA, and documentation.
- Production Vite assets built successfully. Laravel Pint passed; `git diff --check` passed. All cached Blade views were compiled and their generated PHP passed syntax checks.

Expiry tests cover disabled routes/UI/history; opening/purchase dates; invalid incoming dates; combined options without costs; same-price/date aggregation; expired/depleted exclusion; expiry-today boundaries; selected-date isolation; FEFO split and price isolation; stale checkout rollback; explicit overrides; 45-day dashboard boundary; multi-unit sales; exact sales/purchase returns; expired-restock refusal; no-bill returns; write-off cost, duplicate confirmation and partial landed cost; cost permissions; PDF/CSV endpoints; date correction protection; shop time zones; unknown-stock low-stock checks; adjustments; disabled purchase/receipt views; optional batch-number preservation.

## Browser and PDF verification

Browser testing used a separate SQLite QA database with `DEMO=false`, not operational transactions. Fictional product **Expiry QA Milk Powder** was created through the real UI.

| Check | Observed result |
|---|---|
| Opening stock | 20 pieces at cost 100/sell 130, expiry 15 Jan 2027; 15 at cost 110/sell 140, expiry 15 Apr 2027; total 35 |
| Combined POS selection | One chooser showing prices, dates and quantities; no costs |
| Completed sale | Sold 2 from January for 260; January layer decreased from 20 to 18 |
| Purchase | Received 10 at cost 105/sell 140, expiry 15 Jul 2027; separate purchase layer and supplier/source trace |
| Near/expired fixtures | QA-only 2 pieces expiring 17 Oct and 5 already expired on 1 Oct |
| POS blocking | Expired 1 Oct lot absent from valid choices; valid stock excludes expired quantity |
| Alerts | 17 Oct lot shown as urgent with 6 days remaining; changing alert window to 3 days removes it; restored to 45/7 afterward |
| Write-off | Confirmed 2 expired pieces; batch 5→3, cost 500→300, one expense 200 |
| Profit & loss | Other operating expenses 2,950.50 = existing manual 2,750.50 + expiry write-off 200; return write-off line remains zero |
| Customer return | Returned 1 from original sale; layer #63 January increased 18→19; original allocation trace retained |
| Purchase return | Returned 3 from delivery; July layer #65 decreased 10→7; supplier due reduced by 315 |
| Final quantities | Physical 46; sellable 43; expired 3; unknown 0; January 19, April 15, July 7, near 2, expired 3 |
| FEFO UI | Two selling-price choices; price 130 has 21 pieces, price 140 has 22; dates grouped only within their price |
| Receipt option | Existing receipt shows Exp: 15/01/2027 after enabling the option; dual logos and existing receipt fields remain |
| Global off/on | Off product details have no expiry controls/menu/table, report route404; on reveals retained dates and final quantities |
| Mobile | 390×844 requested viewport (browser content375×812); all report actions visible, no page horizontal overflow |
| PDF | Export rendered with Poppler and visually inspected: landscape A4, readable columns, dates, sources, values and page footer |
| Console | No JavaScript errors during final checks; the receipt-icon registry warning found during QA was corrected |

Some edge paths (manager expired-sale override, multi-unit FEFO split, stale-checkout revalidation, unknown no-bill restock policy and multi-page export) were verified by automated tests rather than manually repeated in the browser. Printer hardware output was not available; receipt HTML and generated report PDF were inspected.

Screenshots:

- [Combined POS choices](qa/expiry-pos-choice.jpg)
- [Write-off review](qa/expiry-writeoff-review.jpg)
- [Mobile expiry report](qa/expiry-mobile-report.jpg)
- [Rendered report PDF](qa/expiry-report-pdf.png)

Staff guide: [Expiry tracking](EXPIRY_TRACKING.md), covering all twenty requested topics.

## New files

- `app/Http/Controllers/ExpiryController.php`
- `app/Services/ExpiryReportService.php`
- `app/Services/ExpiryService.php`
- `app/Services/ExpiryWriteoffService.php`
- `app/Support/Expiry.php`
- `config/expiry.php`
- `config/features.php`
- `database/migrations/2026_10_12_000000_add_optional_expiry_tracking.php`
- `docs/EXPIRY_TRACKING.md`
- `resources/js/expiry.js`
- `resources/views/expiry/index.blade.php`
- `resources/views/products/partials/expiry-stock.blade.php`
- `tests/Feature/ExpiryTrackingTest.php`

## Modified source files

- `.env.example`
- `README.md`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/PosController.php`
- `app/Http/Controllers/ResourceController.php`
- `app/Http/Controllers/SaleController.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/StockBatchController.php`
- `app/Http/Controllers/StockController.php`
- `app/Http/Controllers/TransactionReturnController.php`
- `app/Http/Requests/CheckoutRequest.php`
- `app/Http/Requests/PurchaseRequest.php`
- `app/Http/Requests/ResourceRequest.php`
- `app/Http/Requests/SettingsRequest.php`
- `app/Http/Requests/StockAdjustmentRequest.php`
- `app/Http/Requests/StockBatchRequest.php`
- `app/Http/Requests/TransactionReturnRequest.php`
- `app/Models/Product.php`
- `app/Models/ProductStockLayer.php`
- `app/Models/PurchaseItem.php`
- `app/Services/DemoDataService.php`
- `app/Services/NoReceiptSalesReturnService.php`
- `app/Services/ProfitLossService.php`
- `app/Services/PurchaseService.php`
- `app/Services/ResourceService.php`
- `app/Services/SaleService.php`
- `app/Services/SalesReturnService.php`
- `app/Services/StockAdjustmentService.php`
- `app/Services/StockLayerService.php`
- `app/Services/StockReturnService.php`
- `app/Support/AuditPresentation.php`
- `app/Support/Permissions.php`
- `app/Support/Sidebar.php`
- `config/pos.php`
- `phpunit.xml`
- `resources/css/app.css`
- `resources/js/app.js`
- `resources/js/no-receipt-returns.js`
- `resources/js/pos-price-options.js`
- `resources/js/pos.js`
- `resources/js/product-units.js`
- `resources/js/purchase.js`
- `resources/js/returns.js`
- `resources/js/stock-adjustments.js`
- `resources/views/crud/stock.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/pos/partials/price-choice.blade.php`
- `resources/views/products/form.blade.php`
- `resources/views/products/index.blade.php`
- `resources/views/products/partials/opening-row.blade.php`
- `resources/views/products/partials/stock-history.blade.php`
- `resources/views/products/show.blade.php`
- `resources/views/purchases/form.blade.php`
- `resources/views/purchases/show.blade.php`
- `resources/views/reports/index.blade.php`
- `resources/views/returns/show.blade.php`
- `resources/views/returns/suppliers.blade.php`
- `resources/views/sales/receipt.blade.php`
- `resources/views/settings/form.blade.php`
- `resources/views/settings/index.blade.php`
- `routes/web.php`
- `tests/Feature/PosWorkflowTest.php`
- `tests/pos-price-options.test.mjs`

## Generated assets

The Vite manifest and hashed bundles under `public/build/` were regenerated for the application, POS, price options, product units, purchases and stock adjustments. Deploy their manifest and assets together; obsolete hashed files were replaced by the build. QA screenshots above are included under `docs/qa/`.
