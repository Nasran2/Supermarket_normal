# Sales, returns and due collections

Updated 8 October 2026.

The sales directory and invoice view use the existing emerald theme. Invoice actions are labeled View, Edit, Return, Delete and Pay due. Actions follow the existing permissions and transaction state. Search matches invoice, customer name or phone; date/status filters remain available.

## Cashier workflow

- **View:** invoice overview, items, payment/fee details, returns, later collections, notes and printable receipt.
- **Edit:** opens the same POS catalogue and cart with the saved invoice. Add/remove products, change units/quantities, price and line discount in the item popup, set a bill discount, customer/notes and revised payments. Requires `sales.edit` and `pos.access`, the original register open under your account, and no returns/later due collections. Review payment shows how much to collect or refund per method compared with the original net payment. Amount fields describe the full revised invoice; only the adjustment is exchanged. Save keeps the invoice number, checkout token and sale date. Refresh, Back or reopening discards unsaved changes and reloads the latest saved invoice. Reset changes restores the initially loaded invoice; Cancel edit returns to its view. Normal new-sale carts still persist independently.
- **Delete:** reason popup; `sales.void` reverses an active invoice in an open register, restores its stock and reverses automatic business-paid processing expenses. The invoice is retained as voided. Invoices with returns or later collections cannot be deleted, avoiding double reversals.
- **Return:** choose one or multiple items and quantities, or all remaining items. The preview allocates the original invoice discount. Save restores the original primary stock quantities and records historical cost reversal. The return reduces invoice due first, then refunds the excess through the selected active method. Original processing fees are retained. Already returned quantities cannot be returned again.
- **Pay due:** appears only on an active invoice with outstanding balance. The popup accepts full or partial collection, payment method and reference. Cash tender may exceed the payment, with change shown; noncash tender matches exactly. The current invoice balance is checked again on save. Original invoice fees are already in the obligation and are not charged again.

Returns and due collections require the cashier's current open register. They can apply to invoices from closed registers, without modifying the original closed drawer. Each activity records its own register, cashier, time and audit event. UUID submission tokens prevent duplicate returns/payments on retries; sale/register/product locks serialize related changes.

Invoice due is original customer payable minus merchandise returned and net payments retained after refunds. Cash tender is reduced by change. Old customer `opening_due` remains separate; it is not attached to an invoice or collected by this action. New POS sales can use **Complete with due** for a partial payment or **No payment · leave bill due** for a fully unpaid invoice. These sales require an active named customer; the popup supports selecting or creating one. Fully paid walk-in checkout offers the same popup with a Continue as walk-in option. **Pay due** collects later invoice payments. See [due checkout](POS-DUE-SALES.md).

## Accounting and reports

Register closing totals use actual tenders less change, add due collections and subtract refunds by payment method. The closing popup lists return and collection activity, including invoices originating in other shifts. P&L subtracts returned revenue and historical cost on the return date; collecting debt does not create additional revenue. Payment reports include later collections/refunds on their dates. The sales report shows each invoice's current returned amount/due and period return/net-revenue cards. Product-sales columns explicitly retain original quantities and line sales before invoice discounts/returns.

Reports → **Sales returns** uses the existing `reports.sales` permission. **Due collections** uses `reports.payments`. Both support filters, pagination, printing and CSV export; they are also linked under Reports in the sidebar.

## Schema and compatibility

`2026_10_08_001300_add_sale_returns_and_collections` adds `sale_returns`, `sale_return_items` and `sale_collections`, without changing existing invoice amounts. Rollback refuses to discard recorded activity. `2026_10_08_001400_preserve_sale_invoice_time` removes older MariaDB's implicit update of `sold_at`; editing or voiding preserves the original invoice date. Previously altered invoice dates cannot be reconstructed automatically.

Verification was scoped to after-sale changes and split-payment regression: 22 tests / 253 assertions on SQLite, and 10 after-sale tests / 133 assertions on MySQL/MariaDB. Coverage includes discounted partial/full and multi-product returns, fractional converted stock/cost snapshots, over-return/cross-invoice validation, idempotency, closed-register separation, partial due payments/change, overpayment/underpayment, permissions, inactive methods, deletion protection, reports and original invoice date preservation. Production build, PHP/JavaScript formatting and Blade compilation passed.

Isolated browser QA verified invoice detail editing, deletion, a 600.00 due payment with 1,000.00 cash and 400.00 change, a 1,180.00 return reducing due and a separate 1,180.00 cash refund. Main business data was only viewed; no test sale/return/payment was added to it.

Screenshots: [sales directory](screenshots/sales-directory.jpg), [invoice view](screenshots/sale-details.jpg), [return popup](screenshots/sale-return.jpg), [due payment](screenshots/sale-due-payment.jpg).

## Invoice revision implementation

`2026_10_08_001500_add_sale_revisions` stores complete before/after item/payment/fee snapshots, cashier and unique token. Register, invoice and product locks protect the update. Original sold stock is credited for quote validation and restored inside the save transaction before deducting revised quantities. Current unit conversions, catalogue/cost and payment rules apply to the revised invoice; original sold price and discounts are loaded as starting values. Old automatic fee expenses are reversed with history retained; revised business fees are recreated. Revision snapshots retain original payments, including tender/change. A stale version or changed quote rejects the save with rollback. Invoices with returns or later collections use their existing after-sale actions. The legacy metadata PUT endpoint remains compatible.

Revision verification: 42 related tests / 474 assertions on SQLite and 8 revision tests / 104 assertions on MySQL/MariaDB. Includes stock credit/limits, rollback, same-invoice/date preservation, retry tokens, stale edits, repeated methods/fee reversal, converted quantities/cost snapshots, product removal/addition, permissions and after-sale editing restrictions. Browser QA verified refresh and Back reset, quantity/product draft changes, price/line discount and a 120.00 refund adjustment saved on the original QA invoice.

Screenshots: [single-row actions](screenshots/sales-actions-row.jpg), [POS invoice editor](screenshots/sale-pos-editor.jpg), [revised payment](screenshots/sale-edit-payment.jpg).
