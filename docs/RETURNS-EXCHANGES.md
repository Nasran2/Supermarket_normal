# Returns and exchanges

Implemented and verified 10 October 2026.

## Start a return

**Sales → Sales Return** and **Purchases → Purchase Return** open four steps: find the original bill, select quantities, choose the resolution, then review and settle. The original bill also links directly to its return wizard. Search accepts invoice/reference, customer or supplier name/phone; purchase search also accepts product names and notes. Recent active bills are listed. Voided bills cannot be returned.

Sales stock actions are Restock (default), Write off, and Return to supplier. The customer can receive the same product, other products, or money back. Replacement stock follows the POS selling-price groups and FIFO consumption within a price. Same-product exchanges use the original net selling value by default; authorized users can choose current prices. Replacement COGS always follows the outgoing allocation.

Purchase returns remove only stock physically remaining in the original purchase layers. Same-product and other-product replacements create linked normal purchases and new incoming layers. The wizard supports receiving a refund, applying credit to the supplier's other invoices, retaining supplier credit, paying a difference, or adding a difference to the supplier payable.

**Save draft** retains inputs without changing stock, balances, or money. Continue the draft from its detail page. Completion keeps its reference and is protected against repeated submissions.

## Settlement and stock rules

1. The backend calculates the original net return value, including allocated invoice discounts and the configured historical fee refund.
2. That value first reduces the original unpaid bill balance.
3. Only the remaining credit can pay for replacements, reduce other dues, or be refunded. The wizard displays these amounts separately.
4. Cash settlements require the acting user's open register. Closed original registers stay unchanged; the new settlement belongs to the current register. Noncash settlements use enabled configured methods.

Customer credit is allocated to opening due and then eligible other invoices, with records for each allocation. Selecting another customer requires the additional `sales_returns.allocate_other_customer` permission and enabled customer-search policy. Customer and supplier opening balances are not silently edited. Retained supplier credit appears on the supplier profile and can later be applied once to outstanding purchases.

Sales returns reverse the most recently consumed original allocations first, preserving quantities and exact historical costs across repeated partial returns. Restock restores those layers. Write-off and supplier-pending actions do not add sellable stock. Write-off reverses original COGS and records that historical cost once as a **Sales Return Write-Off** expense. Supplier claims automatically split by original purchase supplier; authorized manual supplier selection is required for opening/migrated stock without provenance.

Supplier Returns lists non-sellable claims from customer returns. Authorized users mark them Sent and settle the claim against supplier dues and/or a refund. This is separate from the purchase return wizard, which handles returning sellable purchase stock and receiving replacement deliveries.

## History, receipts and reports

Every completed return retains its original bill, creator, approval/cancellation information, reasons, quantities, historical stock allocations, account allocations and money movements. Replacement invoices/purchases link back to the return. Customers and suppliers show their return/exchange histories. Product stock history links each movement to the return and original bill.

Return detail pages provide an **80mm credit note** with original and replacement references, returned/replacement products, due reductions and settlement. **Settings → Returns → Automatically print return receipt** opens and prints that credit note on completion. Printing uses the browser's normal printer dialog.

Sales and purchase return reports provide date, status, resolution, account, cashier, product, reason and payment-method filters, plus sales stock-action filtering. PDF/CSV exports use the report permissions. P&L, sales/purchase totals, cash/payment reports, account ledgers, dashboard totals and register closing summaries include the corresponding dated return, replacement and cancellation movements.

## Settings and access

Settings → Returns controls enablement, default stock action/refund method, reason requirement, manager threshold, anonymous returns, customer due allocation/search, supplier claims, original payment-charge refund policy and auto-print. Payment-charge refunds default to non-refundable; full and pro-rata options use original stored fees.

Assign permissions through Roles & permissions:

- `sales_returns.view/create/approve/cancel/refund/writeoff/return_to_supplier/apply_customer_due`
- `sales_returns.allocate_other_customer` for applying credit to a different customer's account
- `purchase_returns.view/create/approve/cancel/receive_refund/apply_supplier_credit`
- `supplier_returns.view/manage`
- `returns.view_cost` for historical inventory cost

Existing sale visibility scopes continue to apply. A user with create access can review/print their own return; costs remain restricted. A return above the configured threshold must be completed by a user with approval access, who is recorded as approver.

## Cancellation and historical safeguards

Cancel Return requires its own permission and a reason. It records cancellation rather than deleting the return and reverses linked stock, account credits, write-offs and money using a database transaction. Cash reversal uses the current register and original payment-method snapshot, including when the method was subsequently disabled or changed.

Cancellation is rejected if restored stock has already been consumed, a replacement invoice has subsequent payments/returns, replacement purchase stock has been consumed, retained supplier credit has been spent, or a linked supplier claim has already been sent/settled. Cancel dependent activity first where supported. Linked replacement documents cannot independently be edited/voided, and original documents with return history cannot be rewritten.

Pre-upgrade sales returns remain readable and affect balances/reports. Their cancellation is rejected if the old record lacks exact allocation mappings; these need reconciliation rather than an estimated stock/financial reversal. Older purchases require **Set balance** before returning them if payment tracking has not been established. Stock quantity precision is three decimals; smaller converted returns are rejected.

## Database installation

`2026_10_10_190000_extend_transaction_returns` extends existing sale-return tables and adds purchase returns, supplier claims, exact stock allocations, account allocations and settlement records. It inserts missing settings/permissions without overwriting existing configuration. It has already been applied to the local XAMPP database. A private pre-migration backup was saved under `storage/app/private/backups/`.

For another installation run `php artisan migrate --force`, `npm run build`, and `php artisan optimize:clear`. Migration rollback intentionally refuses to discard return history. The existing CSS changes present before this task were retained in the build.

## Verification

- New return suite: **41 tests passed, 205 assertions** in the final SQLite run. The same 41 tests also passed on isolated MySQL/MariaDB before the final receipt-routing and precision polish (201 assertions).
- Focused return/account/history/visibility regressions: **71 tests passed, 569 assertions** before that final polish; the final full-suite run includes those tests.
- Final complete PHP suite: **253 passed, 46 failed, 3,171 assertions**. A clean unchanged-HEAD baseline reproduced exactly the same 46 failing test names: **zero newly failing tests**. Existing failures include outdated payment-fee expectations, duplicate administrator fixture usernames, and unrelated HR/permission checks. This is not a fully green repository suite.
- Existing JavaScript tests: **20 passed**. Production Vite build, Pint formatting, Blade compilation and `git diff --check` passed.
- Requested browser scenarios **A–F passed** against isolated SQLite fixtures, with final stock, ledgers, P&L and register reconciled by `tests/Support/returns-browser-verify.php`. No QA sales, purchases, returns or payments were added to the business database.

The browser result is recorded in [RETURNS-BROWSER-QA.json](RETURNS-BROWSER-QA.json). Its reconciled values are customer due 190.00, supplier due 600.00, sellable soap stock 27.000, supplier-pending stock 1.000, historical write-off 110.00, net profit 70.00 and expected cash 9,540.00.

Desktop/mobile screenshots are saved in `docs/screenshots/`. Browser console checks reported no errors. The verification/bootstrap helpers refuse to run outside the specifically named disposable SQLite QA database. Do not point automated `RefreshDatabase` tests at the business database.
