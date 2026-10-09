# Grouped navigation and stock adjustments

The existing green theme is retained. Sidebar groups expose list and create links for Sales, Purchases, Products, Categories, Units, Multiple units, Suppliers, Customers, Expenses, Users, Roles and Settings. Expense categories, payment methods and payment charge rules are included. List/detail pages provide the existing view/edit/delete actions. Used records and financial history retain their existing protection and reversal rules. Register history and register/cash reports are grouped under Daily register; its open/close popups remain available on the register screen and top bar.

Reports contains All reports and every entry in `ReportService::TITLES`: Sales, Purchases, Expenses, Profit & loss, Stock, Product sales, Payment methods, Register, Cash summary, Payment processing charges, Card charges, QR charges, Bank charges and Audit log. Each report is filtered by its exact report permission. Native expandable groups support keyboard controls, current-group opening, the desktop icon rail and the mobile/POS drawer.

## Batch corrections

Open Products → Stock adjustments → New adjustment. Search active products by name/SKU/barcode and click a match to add it immediately. A complete barcode adds automatically; arrow keys and Enter select search results. The quantity field is selected after adding, and Enter returns to search. Repeating a product focuses its existing row without duplicating or changing its quantity. Up to 100 products can be included. Choose the default adjustment for new rows once, then change individual rows as needed. Each row supports Add stock, Remove stock or Set counted stock in its primary unit. Decimal quantities obey the primary unit and configured precision. Zero changes permit price-only corrections; zero counted stock is valid.

Selling price and cost inputs are visible in the same row as quantity, without opening another control. Removing stock automatically selects its stock row when only one is available; products with several available rows still require choosing which stock to remove. Removed stock displays that row’s prices as read-only. Search suggestions overlay the following card without clipping. The same search controls are available when editing a batch.

Selling price and cost are optional. Blank keeps the current value; an explicit zero sets zero. Primary-price changes update prices derived from unit conversions, while explicitly configured additional-unit prices stay unchanged. Converted prices/costs are range-checked. Purchase and sale historical snapshots remain unchanged.

Apply records a reference, reason, creator, original/latest stock, cumulative net quantity change and price/cost snapshots. Quantity changes create stock movements. Price-only changes are recorded in batch items and audit logs without inventing a quantity movement. A transaction locks products in ID order and rolls back all rows when one fails. Selection snapshots prevent overwriting stock/prices changed after the form loaded.

History supports reference/reason, date and status filters; detail/history screens provide Print / save PDF. Editing adds a revision and applies further corrections to current inventory. Existing batch products must remain; leave Add stock = 0 to leave their quantity unchanged. More products can be added. Every revision is audited, and cumulative changes allow reversal without undoing intervening sales/purchases.

Reverse adjustment is the delete action for applied batches. It requires a reason, reverses cumulative quantity changes, restores prices/costs changed by the batch, marks it Reversed and retains records. It refuses insufficient stock, stale revisions, changed primary units or newer external price/cost values. Repeated reversal is rejected. Reversed batches are read-only. Unit edits also preserve primary-unit and fractional adjustment history. Migration rollback refuses to discard batch history.

## Access and verification

History/detail use `products.view`; create/product lookup/edit/reverse use `products.edit`. Existing permissions are reused without granting new access. Cashiers cannot read the adjustment product-cost lookup or mutate corrections.

Seven stock/navigation tests plus 13 affected multiple-unit tests passed on SQLite (20 tests, 236 assertions). The seven stock tests also passed on MySQL/MariaDB (109 assertions). Stock tests cover multi-product correction, price-only/zero count, atomic rollback, validation, stale selection/revision, edits, intervening movements, reversal conflicts, unit-history protection, all 14 report routes and permissions. The full suite was not run.

Browser QA created a two-product batch, changed stock and prices/costs, saved a second revision and reversed it in the isolated QA database. Bananas returned to 25 kg, price 240 and cost 180; Bath Soap returned to 74 pcs, price 180 and cost 130. The main database contains no test adjustment. Desktop report sublinks and mobile hamburger → Reports → Stock navigation were verified.

[Adjustment form](screenshots/stock-adjustment-form.jpg), [detail snapshots](screenshots/stock-adjustment-details.jpg), [report sublinks](screenshots/report-sublinks.jpg).

The simplified picker passed eight stock/navigation feature tests (122 assertions) and three JavaScript tests. Browser QA in the isolated QA database verified immediate click/Enter/barcode addition, quantity focus and Enter-to-search, repeated scans, retained price entries, single/multiple stock-row handling and saving a three-product adjustment. [Quick search layout](screenshots/stock-adjustment-quick-search.png).
