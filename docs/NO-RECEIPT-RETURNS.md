# Sales returns without a bill

Implemented and verified 10 October 2026. Sales → Sales Return opens **Return With Bill** by default. **Return Without Bill** adds a second four-step workflow in the same module. Normal purchase returns continue to require an original purchase reference.

## Cashier workflow

1. Select a customer by name, phone or ID, or use Walk-in Customer. Search/scan multiple products by barcode, SKU, name or category. Repeated scans increase the selected primary-unit quantity. Whole-piece and decimal-unit rules also apply on the server.
2. Choose returned quantities, credit prices and independent stock actions: Restock, Write off, or Return to Supplier. Optional item reasons/notes supplement the main reason. Customer history suggests up to five eligible original purchases; **Link This Sale** requires cashier confirmation. Unmatched products remain unverified. Different products can link to different invoices in one return.
3. Choose Same Product, Another Product, or Money / Account Credit. Replacement items use actual inventory and POS selling-price groups. Unverified same-product exchanges use current prices; verified same-product exchanges preserve the original net value, consistent with normal returns. Replacement cost always follows the outgoing stock allocation.
4. Review verified/unverified credit, original due reductions, replacement value, customer due before/after, oldest-due allocations, refund and payment charges. Only confirmed linked items reduce an original invoice automatically. Applying unverified credit to a customer's account is explicit. Walk-in returns cannot change customer due. A higher replacement value can be collected or added to a selected customer's due with permission.

Cash refunds require an open register and both normal refund and no-receipt cash-refund permissions. Disabling no-receipt cash refunds hides/disables Cash for refunds; exchanges, explicit account credit and enabled noncash refund methods remain available. Cash can still collect an exchange difference.

**Save draft** records selections without stock, account or payment effects. Resuming preserves supplier and replacement choices. Completion recalculates on the server, checks the quote snapshot and uses one token to prevent duplicate stock/refund entries.

## Defaults and access

Settings → Returns includes all policies below. Existing settings are preserved during migration.

| Policy | Default |
| --- | --- |
| No-receipt returns enabled | On |
| Suggested credit price | Lowest current active selling price |
| Cash refund without receipt | Off |
| Customer required | Off |
| Always require manager approval | Off |
| Manager approval above | 1,000; zero disables this threshold |
| Change suggested credit price | On, with permission and reason |
| Maximum primary quantity per line | Unlimited; zero disables the limit |
| Search customer history automatically | On |
| Return days limit | Unlimited; zero disables the limit |
| Estimated cost method | Latest known active purchase cost |

Price suggestions also support current default price, latest active layer price, or manual manager confirmation. Missing active price/purchase sources use the current product default and record a fallback source. Latest purchase cost includes its allocated landed costs. Manual estimated costs require approval permission, cost-view access and item notes.

Assign `sales_returns.create` and `sales_returns.no_receipt` for the new workflow. Additional permissions are `sales_returns.no_receipt_cash_refund`, `sales_returns.no_receipt_price_override` (or `sales_returns.override_credit_price`), `sales_returns.no_receipt_supplier`, and `sales_returns.no_receipt_approve`. Normal refund, write-off, supplier-return, account-credit and due-sale permissions still apply. These new permissions are not automatically granted to existing cashier roles. Administrators receive them through the existing permission installer.

Approval uses the existing role permission model; an authorized manager completes the document under their login. It records `approved_by` and `approved_at`. Both general-return and no-receipt approval thresholds apply. Cost values are excluded from product/quote responses and views without `returns.view_cost`. Own/role/all sale visibility protects original matches and return records.

## Inventory and accounting

A no-receipt document has `return_type=NO_RECEIPT`, no fabricated parent sale, and nullable item-level original sale links. Its verification status is VERIFIED, PARTIALLY_VERIFIED or UNVERIFIED. Credit-price suggestions, final prices, sources, overrides/reasons, actor, cost basis, supplier confirmation and original allocations remain auditable.

Verified items use the existing historical discount, fee, quantity and allocation calculations. Restock restores the original layer. Historical COGS and original revenue are reversed exactly once, including returns linked through different invoices.

Unverified restock creates a separate layer with source `NO_RECEIPT_SALES_RETURN`, return number, creator, approved credit/selling-price policy and explicitly **ESTIMATED** cost. It never invents a purchase reference. Write-offs and supplier claims do not add sellable stock. The supplier must be confirmed; recent supplier suggestions describe product purchase history, not provenance of the returned item. A pending supplier claim does not silently reduce a purchase payable.

P&L separates unverified approved credit and estimated recovery from historical revenue/COGS:

- No-receipt adjustment = unverified approved credit minus estimated returned inventory/supplier-claim cost.
- An unverified write-off additionally expenses the estimated cost once. For credit 130 and estimated cost 100, restock produces an adjustment of 30; write-off produces 30 adjustment plus 100 expense, a total loss of 130.
- Replacement sales use their actual outgoing COGS and current payment charges. Cancellation reverses the recorded adjustments, stock, claims, credits and money in its own dated audit trail.

A supplier claim retains its estimated/historical cost source and moves through Pending, Sent and Settled. Cancellation is blocked after goods are consumed or dependent supplier/collection/return activity prevents an exact reversal. Original invoices with linked item-level return history cannot be rewritten or independently voided.

Without a receipt, the system cannot prove purchase date, original supplier/cost, or that the physical item has never been returned. A configured days limit requires the customer's claimed date for unverified items. Selected-customer recent-return warnings and configurable approvals provide controls without silently fabricating facts.

## History, receipts and migration

Customer histories, stock movements, return detail pages, the daily register, payment activity and report/P&L exports include no-receipt returns. The 80mm credit note identifies the type, customer/walk-in, item-level invoice links or their absence, replacements and settlements. Reports filter by type, verification, customer, product, date, action, resolution, cashier and approver. Return report totals include with/without bill, cash refunds, due credits, restock, write-off and supplier-return values. CSV retains complete columns; the PDF combines related fields for readable printing.

Migration `2026_10_10_210000_add_no_receipt_sales_returns` extends existing return tables, backfills historical totals and item snapshots, and installs missing settings/permissions. It has been applied to the local XAMPP database after a private backup in `storage/app/private/backups/`. Operational financial record counts were checked against that backup; historical totals were backfilled, and no QA transactions were added to the operational database. Rollback refuses to discard financial audit history.

For another installation: `php artisan migrate --force`, `npm run build`, and `php artisan optimize:clear`.

## Verification

- **34 new tests plus 41 existing return tests: 75 passed, 413 assertions**, on SQLite and isolated MySQL/MariaDB. Coverage includes all 30 requested cases, visibility/cost access, historical fee sharing, cancellation and normal purchase detail/printing regressions.
- Full PHP suite: **287 passed, 46 failed, 3,385 assertions**. The failing test names exactly match the prior clean-HEAD baseline; zero new failures. The repository still has those unrelated pre-existing failures.
- Existing JavaScript suite: **20 passed**. Vite build, Pint, Blade compilation and diff checks pass.
- Browser QA completed repeated barcode entry, multiple products, optional/named customers, original matching, two-invoice and mixed returns, independent stock actions, both exchange modes, current price selection, due/refund splits, supplier confirmation, draft resumption, disabled-cash/noncash refund and exchange cancellation. Desktop/mobile screenshots and the exported PDF were visually checked.

[Browser reconciliation](NO-RECEIPT-BROWSER-QA.json) records final customer due 220.00, supplier payable 800.00, soap stock 26.000, shampoo stock 21.000, estimated pending claim 150.00, net profit -140.00 and register cash 9,880.00. These are disposable QA fixtures.

`tests/Support/no-receipt-browser-bootstrap.php` and `no-receipt-browser-verify.php` refuse to run outside the specifically named `/tmp/twinsofte-no-receipt-ui.sqlite` database. Never point `RefreshDatabase` tests at the business database. Browser screenshots are in `docs/qa/`.
