# Product discounts and optional stock tracking

In **Products → Add product** or **Edit product**:

- **Maintain stock** is enabled by default, including for existing products. Leave it enabled for counted goods. Switch it off for items sold without a stock count.
- **Default discount** supports None, Fixed amount, or Percentage. Fixed discounts apply once to the entire product line: two Rs. 100 items with a Rs. 10 default discount total Rs. 190. A 10% default totals Rs. 180.
- Defaults apply automatically when an item is added to the cart and survive a page reload. Editing a line discount replaces the default; **Use product default discount** or **Reset pricing** restores it.
- The global POS Discounts switch still applies. A saved product default is approved catalogue pricing, so it does not require the cashier's manual-discount permission or consume the cashier's manual-discount limit. Extra manual discounts retain the existing permission and limit checks.
- Fixed defaults stop at zero rather than making a line negative. The server loads the configured discount itself; client-supplied preset values cannot raise it. Changed product settings invalidate an existing payment quote.

Untracked products can be sold with zero inventory. Sales, purchases, returns and voids retain amounts and cost snapshots without generating inventory layers or stock movements. Inventory reports and low-stock alerts exclude these products. Transaction lines preserve whether stock was tracked at the time, so a later product setting change does not turn an old untracked transaction into inventory.

Existing counted stock must be adjusted to zero before Maintain stock can be switched off. The change retains old transaction and stock history. Purchase charges involving untracked products must use Expense treatment rather than inventory capitalization.

The additive migration `2026_10_11_020000_add_product_discounts_and_stock_tracking.php` gives existing products Maintain stock enabled and no default discount. Existing transaction lines retain stock tracking.

## Validation

- 14 product-option feature tests cover default persistence, fixed and percentage math, fractional quantities, permissions, forged presets, quote changes, nonnegative totals, tracked/untracked checkout, returns, cancellations and purchases.
- A focused backend run of 99 tests passed with 615 assertions, including invoice/no-receipt returns, purchase charges and Documentation routing.
- 20 existing frontend tests passed; production assets built successfully.
- Browser checks in an isolated fictional store confirmed product create/edit/list, automatic Rs. 190 totals for two Rs. 100 items, draft reload, manual override/reset, server payment quote, and mobile form fit.
- All 21 staff guides render and all 141 walkthrough frames resolve to existing screenshots and measured controls. Product guide captures were updated for these settings.
- Wider existing suites continue to report older failures in fee expectations, access rules and unit-preset fixtures; these are outside this product feature.
