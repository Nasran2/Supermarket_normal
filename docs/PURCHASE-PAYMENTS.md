# Purchase payments

Purchases now support full payment, a partial payment, or an unpaid supplier balance. The form, purchase list and bill details share the existing emerald design and adapt to mobile screens. Product and supplier suggestions remain visible above surrounding cards.

## Receive and pay

1. Open **Purchases → Add purchase**. Select an existing supplier or use **+** to add one, then enter the delivery reference and date.
2. Search products by name, SKU or barcode. Enter the delivery quantity, selected unit, cost and incoming selling price. Existing stock retains its prices.
3. Choose **Paid in full**, **Partial payment**, or **Leave due**. Leave due is the default. For a payment, choose an active payment method and optionally record its reference. Full payment uses the server-calculated bill total; partial payment must be greater than zero and below the total.
4. Review the live purchase total, paid amount and balance due. **Receive stock & save** records the delivery and its initial payment together. A rejected payment rolls back the entire save.

Cash supplier payments require the cashier's open register and create an OUT movement. Cash refunds create an IN movement. Noncash supplier payments retain their method and reference without changing drawer cash. These entries record payments; they do not transfer money through a bank or payment gateway. Do not enter a second manual drawer withdrawal for the same supplier payment.

## Pay the remaining due

Use **Pay due** on the purchase list or details. The popup starts with the full remaining balance focused and selected. Keep it to settle the bill, or type a smaller payment. Each payment updates the paid/due figures and appears in payment history with its method, reference, cashier and date. Repeated submission of the same payment token does not record it twice. The server locks and checks the current balance, rejecting overpayment even if another payment was recorded while the popup was open.

Payment status is **Paid**, **Partial**, **Unpaid**, **Unrecorded**, or **Voided**. List filters support reference, supplier and delivery dates. The purchase report includes current paid, due and payment status.

## Existing purchases and corrections

The upgrade preserves previous bills and marks their payment status **Unrecorded**, because the old schema did not record supplier payments. Use **Set balance** to enter the amount previously paid and confirm it. This opening balance does not move register cash. It enables tracking and subsequent due payments without assuming that every older purchase is unpaid.

Editing a delivery preserves its payment ledger. The bill total cannot be reduced below net payments, and the supplier cannot change while a positive payment balance remains. Record an actual supplier refund before reducing a paid bill or voiding it. Refunds retain their history and reopen the corresponding balance until the bill is revised or voided. Voiding keeps the original invoice and payment history; existing protections still prevent reversing delivery stock that has already been used.

Permissions reuse **purchases.create** for initial receipts, **purchases.edit** for later payments and setting previous balances, and **purchases.delete** for refunds/voiding. Purchase payment records are separate from operating expenses, so purchase payments are not counted a second time in profit calculations.

## Upgrade and verification

Migration: `2026_10_09_000100_add_purchase_payments.php`. Back up the database outside the public web directory before upgrading another installation, then run `php artisan migrate` and `npm run build`. This installation's migration is applied.

Verified on 9 October 2026:

- Full PHP regression: **181 tests, 1,704 assertions passed**.
- Purchase payment tests on isolated MySQL: **12 tests, 117 assertions passed**.
- JavaScript draft and purchase amount checks: **8 passed**.
- Production frontend build, Blade compilation and diff whitespace check passed.
- Isolated browser workflow: purchase total Rs. 1,430; initial cash Rs. 500; bank payment Rs. 300; final QR payment Rs. 630. Status becomes Paid and all three ledger entries remain visible.
- Mobile form, details and list checked at a 390-pixel viewport without page overflow. Browser console showed no warnings or errors.

Screenshots: [purchase form](screenshots/purchase-payment-form.png), [partial purchase](screenshots/purchase-partial-details.png), [pay due popup](screenshots/purchase-pay-due.png), [paid bill](screenshots/purchase-paid-details.png), [mobile form](screenshots/purchase-mobile-form.png), [mobile details](screenshots/purchase-mobile-details.png).

Browser transactions used the separate `twinsofte_supermarket_ui` database. No test purchase or supplier payment was added to the operational database.
