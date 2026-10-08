# POS workspace and split payments

Updated 8 October 2026. The POS uses the full available width, with products on the left and the cart on the right. Its navigation is hidden until the hamburger is opened. Administration screens keep their usual sidebar. On phones, the product list has its own scroll area and the cart appears below it.

## Cashier workflow

1. Open the POS. When your register is closed, the opening popup appears automatically and the product/cart workspace is locked. Enter opening cash with the keypad or keyboard and tap **Open register & start selling**. Then scan or tap products, adjust quantities, select a customer and enter any permitted discount.
2. Tap **Take payment** (F4). Cash received starts at the full amount, focused and fully selected. Type immediately to replace it with the customer's tender, or press Enter once the quote is ready to complete an exact payment. F1 returns to product search.
3. For one method, choose it. For cash, enter what the customer hands over using the amount field, touch number pad, Exact amount or denomination buttons. The popup shows balance due and cash change.
4. For multiple methods, tap **Split payment**, change the first **Sale amount** to its share of the bill, then select another method. The new method starts with the unallocated balance. Repeat for Cash + Card + QR or any other configured combination. Tap a selected method again to add another separate entry, such as a second card with a different amount and reference. Up to ten payment entries are supported. Remove unused entries with their remove button.
5. Cash received defaults to the exact collection, including any configured customer fees. A manually entered amount is preserved when the quote recalculates. Card/QR/other noncash collections are calculated from their shares and configured customer fees. Optional references expand within each payment row.
6. **Complete sale** enables only when the full discounted bill is allocated, every selected share is positive (except a single zero-total sale), and tender covers each method's collection. Only cash can produce change. The saved receipt lists each payment and its fees.

The keypad supports decimals, backspace, Clear amount and Use remaining. The popup scrolls within its panels on desktops/tablets and uses one column on phones. Touch targets for the keypad are at least 54px tall; primary controls and quantity buttons are at least 44px. Single noncash payments hide the unused keypad.

Payment focus verification was limited to the changed flow in the isolated UI database: full amount selected on opening, typing before the quote response preserved, insufficient cash blocked, and Enter completed exact cash (180.00) and changed cash (500.00 received, 320.00 change). JavaScript syntax, formatting and production build passed. [Selected cash amount](screenshots/payment-autofocus.jpg).

## Opening and closing registers

Open and close controls use popups throughout the app. In the POS, the badge or **Close register** opens the current shift summary. It lists all invoices, completed/voided counts, sales, discounts, cash, card, QR and online-transfer collections, payment-entry counts and both customer-paid and business-paid processing fees. Voided sales remain listed but are excluded from totals.

Count the drawer, enter **Actual cash counted**, and review the live cash difference before confirming. Expected cash is opening cash plus cash collections (after change) plus cash in, less cash out and cash expenses. After closing, the popup shows actual cash and the saved difference; **Done** locks the POS behind the next opening popup. The same summary is available from register history and can be printed. Products, quotes and sale completion are also blocked server-side when the register is closed. The closing popup identifies its register so a stale popup cannot close a newer shift.

Sixteen sample products (`DEMO-001` through `DEMO-016`) are installed with positive stock in groceries, drinks, snacks, household and fresh produce. Opening quantities are logged as stock movements. The optional `SampleProductSeeder` creates missing samples only; rerunning it preserves stock after sales or adjustments.

## Fees and accounting

Rule thresholds use the **full discounted bill**, preserving bill-based thresholds. Percentage fees apply only to the **base amount allocated to that method**. A matching fixed fee applies once to each positive payment entry, including repeated uses of the same method. Zero/unallocated draft rows do not add a fee and cannot complete a positive sale.

Example: a 10,000 bill split into Cash 4,000 + Card 3,000 + QR 3,000. With Card 3% and QR above 5,000 at 10%, customer fees are Card 90 and QR 300. The customer collections are Cash 4,000, Card 3,090 and QR 3,300: total 10,390. If cash received is 5,000, cash change is 1,000. Card/QR portions cannot create change or compensate for an unpaid portion.

Business-paid fees create separate linked automatic expenses per affected payment entry, reducing P&L once. Mixed Customer/Business fee bearers work within the same sale. Registers include only their cash collections and count the sale as one transaction. Payment reports aggregate each method's allocated base and fees; sales reports/dashboard list every method. A sale void restores stock once and reverses all of its automatic processing expenses. Existing single-payment history and the original single-method request format remain supported.

## Implementation and upgrade

Migration `2026_10_08_000600_allow_split_sale_payments` adds the indexed one-to-many payment relationship. `2026_10_08_000700_allow_repeated_payment_methods` removes its temporary unique (sale_id, payment_method_id) constraint so every tender can be stored separately. Both preserve existing rows and foreign keys; rollback refuses incompatible history. `2026_10_08_000800_preserve_register_opening_time` removes MariaDB’s implicit timestamp-on-update behavior from register opening times.

`SplitPaymentService` centralizes allocation, fee and tender checks; `SaleService` saves all payments, stock and expenses in its existing transaction. Signed server quotes include every method/share/rule. Checkout tokens still prevent duplicate saves. Payment-specific references are editable only within their own sale. Report, receipt and register queries use the full payment collection.

Apply this update on another installation with:

```sh
php artisan migrate
npm ci
npm run build
php artisan optimize:clear
```

The migration and build are already applied here. No new environment variables or permissions are required. The normal sales.create and pos.access permissions continue to apply.

## Verification

**This correction was checked with 7 focused tests / 85 assertions on both SQLite and MySQL/MariaDB.** They cover repeated card/cash entries, per-entry fixed and percentage fees/references/expenses, register breakdowns and voids, cash differences, preserved opening times, closed-register workspace/API protection, stale closing popups and sample-stock idempotence. The full suite was not rerun for this correction.

The earlier split-payment release passed 75 tests / 513 assertions on both databases. Those cases cover two/three methods, per-share fees with full-bill thresholds, strict threshold equality, mixed bearers, multiple expenses, voids, cash change, transaction counts, incomplete/overallocated/inactive/malformed payments, tender validation, changed quotes, idempotent retries, rollback if the second expense fails, per-payment references and cross-sale edit protection.

In the isolated browser QA database, INV-000011 completed with Cash 6,000 + Card 4,000, fee 120, customer payable 10,120, tender 11,120 and change 1,000. INV-000012 completed with Cash 4,000 + Card 3,000 + QR 3,000, fees 390, customer payable 10,390, tender 11,390 and change 1,000. Both receipts show all methods and amounts. These test transactions are separate from the operational database.

POS and payment popup checked at 1920, 1366, 1024, 768, 430 and 390px: no page/dialog horizontal overflow. Hamburger opening/Escape closing, barcode entry, touch-keypad cash entry and single noncash keypad behavior were verified. PHP/JavaScript formatting, production build and Blade compilation passed.

Screenshots: [right-hand cart](screenshots/pos-right-cart.jpg), [closing popup](screenshots/register-closing.jpg), [earlier POS workspace](screenshots/pos-workspace.jpg), [payment popup](screenshots/split-payment.jpg), [two-method receipt](screenshots/split-receipt.jpg), [three-method receipt](screenshots/three-method-receipt.jpg). Payment screenshots use isolated QA data. Physical printer calibration remains the previously documented device check.

The latest isolated browser QA checkout, INV-000013, used Cash 2,000 + Card 1,500 + Card 2,500 + QR 2,000 + Bank Transfer 2,000. Card fees were 45 and 75; QR fee 200; total collected 10,320, tender 10,820 and change 500. The register popup grouped both card entries correctly. Opening cash was entered using the touch keypad; opening and closing were verified end to end. Register/payment screenshots use isolated QA data; the right-hand cart preview shows the installed sample products.

The closing popup was checked at desktop, 1024px tablet and 390px phone widths without page/dialog horizontal overflow. Payment tables scroll horizontally on narrow screens to keep amounts readable. Cash keypad targets are 54px high. QA REG-2 closed with expected cash 2,500, actual 2,490 and difference -10, preserving its original opening time.

## Add customers from the cart

Tap the **+** button beside the Customer selector. Enter the required name and optional phone, email and address, then tap **Save & select customer**. The popup saves an active customer, inserts it into the selector and selects it without reloading the POS or changing the cart. Cancel/Escape returns to the order; checkout shortcuts stay inactive while the popup is open. Validation errors retain the entered fields. Creation requires the existing `pos.access` and `sales.create` permissions and an open register, and uses the normal `customers.save` audit event.

This change passed four focused tests (32 assertions), covering creation/contact fields/auditing, optional fields and validation, permissions and closed-register protection. Browser QA verified automatic selection with a one-item cart kept at 240.00, cancel/Escape and a 390px phone popup with 48px input/button targets and no horizontal overflow. [Popup screenshot](screenshots/add-customer.jpg).

## Customer opening dues and views

The popup and customer create/edit page now accept **Old balance (due)**: a non-negative amount with at most two decimal places. It records an outstanding debt brought forward from earlier purchases. Omitted or empty values on creation default to zero. Migration `2026_10_08_000900_add_customer_opening_due` adds the decimal column without changing existing balances. Updates are audited; a customer with a positive due must be deactivated rather than deleted, unless the balance is corrected first.

The directory shows total/active customers, accounts with dues and total outstanding due, with contact search and balance/status filters. Profiles show contact details, opening due and purchase history. Paid purchases are shown separately, and new fully paid POS sales neither collect nor reduce the opening due. The POS shows the selected customer's due beside the Customer label without adding it to the order total. A repayment/credit-sale ledger is outside this change.

Verification was limited to **11 customer-focused tests / 121 assertions**, passing on both SQLite and MySQL/MariaDB. Checks cover persistence, defaults, validation, auditing, filters/profiles, deletion protection and separation from paid sales/register totals. Isolated browser QA created dues of 9,500.75 through the POS and 2,400.50 through customer management; the directory correctly showed 11,901.25. Desktop and 390px phone layouts were checked, including a cart total of 240.00 beside an old due of 9,500.75. No test customer debt was added to the operational database. Screenshots: [customer form](screenshots/customer-form.jpg), [directory](screenshots/customers-directory.jpg), [profile](screenshots/customer-profile.jpg), [POS due](screenshots/pos-customer-due.jpg).
