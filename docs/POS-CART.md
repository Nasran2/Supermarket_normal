# Compact cart and saved orders

Each item uses one row: clickable product name, minus/quantity/plus controls, final amount and remove action. Long names truncate visually and remain available through the popup and accessible labels. Unit selection, edited price and fixed/percentage line discounts are in the item popup; the main cart has no separate discount input or discount label. **Cancel** closes the popup without applying changes. **Reset pricing** returns to the selected unit's catalogue price and removes its line discount.

**Bill discount** below the subtotal opens a popup for a fixed amount or percentage on the full bill. It applies to the subtotal after item discounts and recalculates when quantities change. The cart and payment popup show the bill discount, and receipts print it once. Cancel leaves the previous discount; Remove discount clears it. It saves with the draft and clears with an empty/cancelled/completed order. Amounts above the remaining subtotal and percentages above 100% are rejected. Existing cashier limits apply to combined price reductions, item discounts and bill discounts. Legacy API `discount` amounts remain supported; new `bill_discount_type` / `bill_discount_value` fields take precedence when supplied.

## Pricing

The popup's discount applies to the entire line. For quantity 2 at an edited price of 350, an amount discount of 20 gives 680. A percentage discount is computed after the quantity × edited unit price has rounded to cents. Price overrides do not become formal invoice discounts; catalogue/edited prices and discount snapshots remain in the sale item and audit.

Sale subtotal is the sum of edited line subtotals before discounts. Sale discount combines line discounts with any legacy/API invoice discount; sale amount deducts this once. Processing fee shares and thresholds use the discounted sale amount. Customer receipts show net line amounts and effective prices, without a separate line-discount label. Historical invoice discounts still display correctly. Registers and internal reports retain real discount totals.

Existing discount settings apply to line discounts and price reductions. A cashier cannot bypass their maximum discount by changing the unit price; increasing prices on other items cannot offset the reduction budget. Quote hashes include the adjustments, and later changes require a new quote. Stock/cost conversions and reversal snapshots remain unchanged.

Migration `2026_10_08_001100_add_sale_line_adjustments` adds `catalog_price`, `line_subtotal`, `line_discount`, `discount_type` and `discount_value` to sale items, backfills existing price/total and leaves old sales unchanged. Rollback refuses to discard adjusted pricing history.

## Saved drafts

Drafts save automatically in browser storage under an application/cashier-specific key. They include quantities, selected units, customer ID, price overrides, line discounts and the checkout token. Refreshing or returning to the POS restores the draft and refreshes product/unit metadata. Unavailable products or units remain visible until edited/removed, and cannot be paid accidentally. Storage contains only the current draft; it is not a sales record or a cross-device sync service.

**Clear order** opens a touch confirmation. **Keep order** retains the cart, while confirming **Clear order** removes this cashier's draft. Successful payment clears it immediately, before showing the receipt. Failed payment retains the draft. An attempted checkout preserves its token; restored drafts check `/pos/checkout-status` for a sale already completed by that cashier. A completed token clears the draft instead of creating another sale. Editing after an uncertain payment first checks its status. Other signed-in cashiers cannot retrieve someone else's checkout result.

Draft storage depends on the same browser/profile and its stored site data. When storage is unavailable, the POS displays a warning. Restored orders wait for product/status checks before payment. Clearing browser site data also removes local drafts.

## Verification

Twelve focused backend tests (117 assertions) cover edited prices, fixed/percentage line and bill discounts, fractional rounding, validation, cashier limits, disabled discounts, quote changes, fee thresholds and cashier-scoped recovery. The affected multi-unit, register and split-payment tests passed on SQLite during the cart work. Four Node tests cover preserved draft fields/retry tokens, cashier isolation, malformed drafts and backward-compatible bill discount persistence. The full suite was not run.

Browser QA verified a 680 chips line (2 × 350 − 20) and a 1,045 dozen line (1 × 1,100 − 5%), restored together after refresh and navigation with the selected customer. Popup cancellation retained the existing amount; desktop and 390px phone layouts fit without horizontal overflow. INV-000015 completed at 1,725, printed the final amounts without line-discount labels and left an empty cart after refresh. Transaction fixtures stayed in the isolated QA database.

The touch confirmation was verified: Keep order retained its item, while Clear order removed it and remained empty after refresh. A second test sale (INV-000016, 180) followed by immediate refresh also left an empty cart and created one sale. Checkout-status recovery passed its focused backend tests; a response interrupted before completion was not separately simulated.

Full-bill browser QA verified 10% of 1,360 = 136, leaving 1,224; refresh retained the discount. Increasing quantity changed the subtotal to 1,750, discount to 175 and payment quote to 1,575. Cancel kept that discount. A fixed discount of 100 saved after refresh; Remove discount restored 1,750. The popup fit at 390×844. INV-000017 completed in the isolated QA database at 1,650 and printed a single bill discount of 100. Refresh afterward showed an empty cart and no bill discount.

[Compact cart](screenshots/pos-compact-cart.jpg), [item editor](screenshots/pos-line-editor.jpg), [adjusted receipt](screenshots/pos-adjusted-receipt.jpg), [bill discount](screenshots/pos-bill-discount.jpg).
