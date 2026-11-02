# Customer tax invoices

Enable with `CUS_TAX=true` in `.env`, run `php artisan migrate --force`, and clear cached configuration with `php artisan config:clear`. `CUS_TAX=false` hides the bill selector, customer TIN inputs, VAT summaries and tax settings, and denies tax invoice endpoints. Existing records are retained.

Settings → Tax settings (business-settings permission) edits shared shop details, the VAT percentage and price mode, plus an optional note on new tax invoices. Business details use the same saved values as Business information. The default is 18% included in selling prices. Inclusive prices retain the paid total. Exclusive prices add VAT after discounts, before payment, on **all new sales**, for both bill types. This implementation uses one VAT rate for all goods; it does not classify exempt items. Customer payment processing fees remain separate from the goods supply.

Supplier means the shop issuing the invoice. Missing shop TIN, business name, address and phone can be completed directly in the tax popup by users with business-settings permission, then saved with the invoice. Other users see a clear manager-setup message. Customer inputs remain in the form. The header and Save action remain visible while fields scroll.

After payment, two side-by-side cards show static receipt and A4 invoice layout previews. They contain no customer or sale data. The two-column layout also fits mobile screens.

After payment:

- `1`: print the existing thermal receipt.
- `2`: show the A4 tax invoice in a print dialog and open browser printing. A separate Download PDF button is available in that dialog. Walk-in customers and existing customers missing name, TIN, address or phone are prompted to complete those details.
- `Esc` / Cancel: dismiss bill selection. The completed payment is retained.

In the tax print view, the footer shows `1` Open invoice, `2` Download PDF, `3` Print and `4` Next sale / Done. Escape closes this view even while the embedded invoice has focus. These shortcuts are checked by `tests/customer-tax-shortcuts-browser.test.cjs` against an already issued invoice in the disposable fixture.

The POS success message can reopen the selector. Sale details also provides a bill/PDF button. Existing customer data is prefilled; cashiers with customer-create permission can fill missing values, while replacing existing values requires customer-edit permission. New customers are added to the POS picker immediately. Missing customer details are prompted before any supplier-settings warning; saved fields are prefilled and missing fields are highlighted.

Issuance stores a unique, immutable snapshot per sale. Repeated previews/downloads/retries retain the original customer, supplier, VAT amounts, delivery information and invoice number. VAT rate/mode are captured at checkout and preserved during invoice revision. Once a tax invoice is issued, use returns for corrections rather than editing the original sale.

## Verification

- `php artisan test --compact --filter='CustomerTaxInvoiceTest|PosCustomerTest'`: 16 passed, 190 assertions.
- POS draft, stock-price and scanner Node tests: 18 passed.
- `npm run build`, Blade compilation, Pint and `git diff --check`: passed.
- Disposable SQLite browser fixture: Escape, shortcuts 1/2, print, walk-in customer entry, missing existing details, automatic print view for completed details, optional PDF download, immediate picker update, sidebar Tax settings with shared business data and note, and mobile layout passed.
- Separate `CUS_TAX=false` browser session: controls absent and settings/PDF endpoints inaccessible.
- Native A4 print output and downloaded PDFs rendered and inspected; repeated column headers and aligned totals verified.

The wider existing sales regression selection has four pre-existing failures, reproduced with the original SaleService and SaleRevisionService: payment-fee expectations in SaleAftercareTest, SaleLineTest and SaleRevisionTest, plus the other-cashier edit assertion in SaleRevisionTest. No new failures were observed in that selection.

Browser fixtures must use `/tmp/twinsofte-tax-browser.sqlite`, never the shop database. See `tests/Support/customer-tax-browser-bootstrap.php` and `tests/customer-tax-browser.test.cjs`.
