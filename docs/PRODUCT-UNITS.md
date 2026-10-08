# Products and multiple units

## Setup

Open **Products → Add product**. The product page groups identity/category, image, primary unit/pricing, stock and additional units. Product rows show all available unit prices; profiles show stock, conversion equations and prices.

1. Choose the **Primary stock unit**. Enter its cost, selling price, opening stock and low-stock level.
2. Add additional units, or load a matching preset from **Multiple units**. Each equation reads **primary quantity × primary unit = additional quantity × additional unit**. For example, enter `12 pieces = 1 dozen`; do not reverse this relationship.
3. Leave the additional selling price blank to derive it from the primary price. A primary price of 100 gives 1,200 per dozen. Set 1,100 to override it for pack pricing. An explicit zero remains a zero price.
4. Save the product. Preset rows are copied into the product. Later preset changes do not update saved products automatically.

**Multiple units** in Inventory manages reusable presets using the existing unit view/create/edit/delete permissions. Products retain existing product permissions. The starter presets are Pieces & dozen, Kilograms & grams and Liters & milliliters. Run `php artisan db:seed --class=UnitPresetSeeder` after migration on another installation; existing presets are never replaced.

## Selling and purchasing

The cart offers a unit selector for products with additional units. Switching a cart unit resets that product's quantity to one and uses its unit price; each product currently occupies one cart line with one selected unit. Cashier quantities obey the selected unit's whole/decimal setting. A sale of two dozen deducts 24 pieces, and its receipt prints `2 doz` with the per-dozen price.

Purchases also select a unit. The entered cost is **per selected unit**, and saving converts it back to the primary cost. For example, purchasing two dozen at 720 each adds 24 pieces, records a 1,440 purchase and sets primary cost to 60 per piece. Purchase editing/voids reverse the original saved primary quantities and cost before applying corrections. If a saved purchase unit is no longer available, its edit form requires an explicit unit choice.

Stock availability, alerts and valuation always use the primary unit. The primary unit cannot change once a product has stock movements or transaction history. Additional conversions can be edited; completed transactions retain their selected unit/price and saved primary quantity/cost. Changing an order's conversion invalidates an earlier payment quote. Existing single-unit products and submissions that omit a selected unit continue to use the primary unit.

## Precision and safeguards

Conversion quantities support six decimal places; amounts have two decimal places and stock has three. Decimal primary quantities round once to the configured quantity precision (up to three decimals). For `1 yd = 0.9144 m`, selling one meter moves `1.094 yd` with the default precision. Whole-piece stock never rounds a fractional piece: an incompatible quantity is rejected instead. Negative/zero conversions, duplicate units, the primary unit repeated as an additional unit, inactive units, unsupported prices and insufficient stock are rejected. Unit decimal settings cannot discard fractional conversion or transaction history.

Migration `2026_10_08_001000_add_multiple_product_units` adds `unit_presets`, `unit_preset_conversions`, `product_units`, and `unit_id`, `base_quantity`, `base_cost` snapshots on sale/purchase items. Existing rows are backfilled from their original quantity/cost. Rollback refuses to discard conversion data. Product/preset saves include conversion rows in their audit events.

## Focused verification

- 13 multiple-unit tests / 127 assertions passed on SQLite and MySQL/MariaDB: creation, derived/custom prices, validation, stock/cost conversions, reversals after conversion changes, decimal rounding, quote changes, permissions and primary-unit protection.
- The affected split-payment and register regression tests passed on SQLite alongside these tests: 32 tests / 332 assertions total. Six existing single-unit stock/purchase tests also passed (32 assertions). The full suite was not run.
- Isolated browser QA created `Packable Tea (QA)` with 48 pieces, 12 pieces per dozen and 24 per box, pricing 100 / 1,100 / 2,100. INV-000014 sold two dozen for 2,200 and deducted 24 pieces; the receipt showed `2 doz`. Purchasing in dozens offered cost 600 and a whole-number quantity step. Product/conversion and purchase layouts were checked at 390px without horizontal page overflow.
- Sample transaction/customer/product fixtures stayed in the QA database. Main product stock was preserved; only migration and reusable starter presets were applied to the operational installation.

[Product form](screenshots/product-form-units.jpg), [product units](screenshots/product-multiple-units.jpg), [unit presets](screenshots/unit-presets.jpg), [catalogue](screenshots/products-catalogue.jpg), [selected-unit receipt](screenshots/multiple-unit-receipt.jpg).
