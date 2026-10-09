# Purchase payments

Purchases infer full payment, partial payment, or an unpaid supplier balance from the amount given. No separate payment-status choice is needed. The form, purchase list and bill details share the existing emerald design and adapt to mobile screens. Product and supplier suggestions remain visible above surrounding cards.

## Receive and pay

1. Open **Purchases → Add purchase**. Select an existing supplier or use **+** to add one, then enter the delivery reference and date.
2. Search products by name, SKU or barcode. Enter the delivery quantity, selected unit, cost and incoming selling price. Existing stock retains its prices.
3. Add shipping, handling or other charges as needed. **Record as expenses** is selected by default. Alternatively, choose **Add to product cost** to distribute the charges across this delivery.
4. Enter **Amount paid now** and select a payment method when the amount is greater than zero. Zero leaves the bill Unpaid; a smaller amount is Partial; the full amount or more is Paid. Any excess is shown as **Change balance**, retained in payment history, and excluded from the net payment and cash withdrawal.
5. Review the live purchase total, paid amount and balance due. **Receive stock & save** records the delivery and its initial payment together. A rejected payment rolls back the entire save.

Cash supplier payments require the cashier's open register and create an OUT movement. Cash refunds create an IN movement. Noncash supplier payments retain their method and reference without changing drawer cash. These entries record payments; they do not transfer money through a bank or payment gateway. Do not enter a second manual drawer withdrawal for the same supplier payment.

## Pay the remaining due

Use **Pay due** on the purchase list or details. The popup starts with the full remaining balance focused and selected. Keep it to settle the bill, or type a smaller payment. Entering more than the due settles only the remaining balance and records the excess as change returned. Each payment updates the paid/due figures and appears in payment history with its method, reference, cashier and date. Repeated submission of the same payment token does not record it twice. The server locks and checks the current balance, applying at most the current outstanding balance. Fully settled bills reject further payments, and refund amounts cannot exceed net payments.

Payment status is **Paid**, **Partial**, **Unpaid**, **Unrecorded**, or **Voided**. List filters support reference, supplier and delivery dates. The purchase report includes current paid, due and payment status.

## Shipping and other charges

Up to 30 named charges can be added or removed. Their sum is included in the supplier bill and balance due.

- **Record as expenses** (default): each charge creates a linked, protected purchase expense. Profit & loss includes these charges once, separately from processing fees. An unpaid bill still records the delivery expense; later supplier payments only settle its balance and do not create another expense or drawer expense.
- **Add to product cost**: distribute charges by each line's purchase value. When every line has zero value, distribute evenly across the lines. Allocations retain every cent, including rounding remainders. Incoming stock retains its exact total and remaining cost; sales, returns, revisions, stock reports and reversed adjustments preserve these costs. The displayed unit cost is rounded to two decimals, while the cost ledger preserves the full allocation. Existing stock and selling prices are unchanged.

Editing a delivery reverses its old linked expense entries and records the revised charges. Voiding reverses linked expenses while retaining the bill and history. Stock-use and payment/refund protections still apply. Automatic purchase expenses are corrected through the purchase, rather than edited independently.

## Document numbers

New automatic purchase references use **pur-YYYYMM-0001**, counting within the delivery month. Sales use **INV-YYYYMMDD-00001**, counting within the sale day. For example, **pur-202610-0001** and **INV-20261009-00001**. Counters reset for a new period, skip existing matching documents, and are locked within the save transaction. Failed saves do not consume a number. The purchase preview is provisional until save; existing invoices retain their numbers when viewed, returned, paid or revised.

## Existing purchases and corrections

The upgrade preserves previous bills and marks their payment status **Unrecorded**, because the old schema did not record supplier payments. Use **Set balance** to enter the amount previously paid and confirm it. This opening balance does not move register cash. It enables tracking and subsequent due payments without assuming that every older purchase is unpaid.

Editing a delivery preserves its payment ledger. The bill total cannot be reduced below net payments, and the supplier cannot change while a positive payment balance remains. Record an actual supplier refund before reducing a paid bill or voiding it. Refunds retain their history and reopen the corresponding balance until the bill is revised or voided. Voiding keeps the original invoice and payment history; existing protections still prevent reversing delivery stock that has already been used.

Permissions reuse **purchases.create** for initial receipts, **purchases.edit** for later payments and setting previous balances, and **purchases.delete** for refunds/voiding. Purchase payment records are separate from operating expenses, so purchase payments are not counted a second time in profit calculations.

## Upgrade and verification

Migrations: `2026_10_09_000100_add_purchase_payments.php`, `2026_10_09_010000_add_purchase_charges_and_document_sequences.php`, and `2026_10_09_010100_track_remaining_landed_cost.php`. Back up the database outside the public web directory before upgrading another installation, then run `php artisan migrate` and `npm run build`. This installation's migration is applied.

Verified on 9 October 2026:

- Full PHP regression: **190 tests, 1,810 assertions passed**.
- Purchase payment tests on isolated MySQL: **21 tests, 223 assertions passed**.
- JavaScript draft and purchase amount checks: **10 passed**.
- Production frontend build, Blade compilation and diff whitespace check passed.
- Isolated browser workflow: purchase total Rs. 1,430; initial cash Rs. 500; bank payment Rs. 300; final QR payment Rs. 630. Status becomes Paid and all three ledger entries remain visible.
- Current desktop form and bill details verified in the browser with no console warnings or errors. The existing purchase layout was previously checked at a 390-pixel viewport; this round's viewport override did not apply to the background QA tab, so the new charge controls were verified on desktop.

Current screenshots: [automatic payment and charges](screenshots/purchase-automatic-payment-charges.png), [allocated charge preview](screenshots/purchase-allocated-charge-form.png), [paid bill with change](screenshots/purchase-charges-paid-details.png), [due payment with change](screenshots/purchase-due-change.png).

Earlier workflow screenshots: [purchase form](screenshots/purchase-payment-form.png), [partial purchase](screenshots/purchase-partial-details.png), [pay due popup](screenshots/purchase-pay-due.png), [paid bill](screenshots/purchase-paid-details.png), [mobile form](screenshots/purchase-mobile-form.png), [mobile details](screenshots/purchase-mobile-details.png).

Browser transactions used the separate `twinsofte_supermarket_ui` database. No test purchase or supplier payment was added to the operational database.
