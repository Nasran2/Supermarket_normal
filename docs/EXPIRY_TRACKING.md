# Expiry tracking

Expiry tracking helps your shop keep products with different expiry dates separate. A **batch** is a group of stock received together. For example, two deliveries of the same milk powder can have different prices and different expiry dates.

## 1. What the feature does

Each stock batch has its own date, optional batch number, remaining quantity, cost and selling price. A product can have many batches. Stock is counted once, in the product's main unit. Products without expiry tracking keep working normally.

## 2. Enable it

Ask the person who manages your server to set this in `.env`:

```dotenv
EXPIRE=true
```

After installing this update, they must run:

```sh
php artisan migrate --force
php artisan optimize:clear
npm run build
```

Then sign in again or reload the page. **Settings → Expiry dates** and **Reports → Expiry report** appear for users with permission. In a demo installation, settings remain locked by the existing demo rules.

## 3. Disable it

Set `EXPIRE=false`, then run `php artisan optimize:clear`. Reload any open POS screens. Expiry controls, reports, alerts, badges and receipt dates disappear. Normal selling uses the existing price-selection workflow.

Saved dates, batches, allocations and write-offs remain in the database. Re-enabling the feature reveals the stored data again. Turning the feature off does **not** remove old expiry records or restore written-off stock.

## 4. Understand the server setting

`EXPIRE` is an optional server switch, off by default. Staff cannot turn it on from the website. The application reads `config/features.php`; it does not read `.env` directly in individual screens.

## 5. Clear cached settings after changing `.env`

Always run `php artisan optimize:clear` after changing `EXPIRE`. If your hosting setup uses cached configuration, rebuild its configuration cache only after making the change. Changing `.env` alone may leave the old configuration active.

## 6. Choose which products track expiry

Open **Products → Add product** or edit an existing product. Keep **Maintain stock** ticked and tick **Track expiry** only for the relevant products. Leave it unticked for bags, services or other items where batch dates are not needed.

New and existing products default to no expiry tracking. An administrator can change the new-product default in Expiry settings. You cannot enable expiry tracking without maintaining stock.

Existing undated stock is not given a made-up date. After checking the packaging, an authorized user can open **Product details → Stock by expiry → Correct date**, enter the date and explain the correction. Known dates on batches already used in sales are protected; use an adjustment to separate remaining stock with a different physical date instead.

## 7. Enter opening stock

Tick Track expiry before entering opening stock. Each opening row has quantity, cost, selling price, expiry date and an optional batch number. Add another row for a different delivery/date.

| Quantity | Cost | Selling price | Expiry |
|---|---:|---:|---|
| 20 pieces | Rs. 100 | Rs. 130 | 15 Jan 2027 |
| 15 pieces | Rs. 110 | Rs. 140 | 15 Apr 2027 |

This creates 35 pieces of physical stock, in two separate batches. A positive opening row needs a valid date that has not expired. Zero opening quantity creates no stock batch.

## 8. Receive a purchase

Open **Purchases → Add purchase**, choose the supplier and add the products. Expiry fields appear only for products that track expiry. Read the date on the packaging and enter it for that purchase row. The date is required by default and must be later than the receiving date and must not already be expired.

Receiving the same product at the same price with another date creates another batch. It does not overwrite an earlier delivery's date. Editing a purchase requires the expiry-edit permission to change its dates. Existing dates are preserved when the server feature is off.

## 9. Sell a dated batch at the POS

Scan the barcode or choose the product. If only one sellable price/date option exists, the product goes straight into the cart. If several exist, one window shows the price, expiry date and available quantity together. Choose the date printed on the product in the customer's basket.

A dated cart line stays separate from another date. Reloading a saved cart retains its selected date. Expired stock and stock with unknown expiry are excluded by default. Stock is checked again when payment is completed.

## 10. Understand several prices and dates

The selector combines **selling price + expiry date**. It does not show cost prices to the cashier. Different cost records at the same selling price and expiry date are grouped into one choice; their original cost records remain separate behind the scenes.

If you select the January batch with two pieces remaining and enter five pieces, the system refuses that quantity. Reduce it to two, then add the other three from another batch as a separate cart line.

## 11. Use earliest expiry first (FEFO)

FEFO means “first expiry, first out”. In **Settings → Expiry dates → When a product has several expiry dates**, choose **Automatically use the earliest expiry first**.

Example: January has two pieces and April has ten, both at Rs. 130. Selling five automatically uses two from January and three from April. If April costs the customer Rs. 140 instead, the system requires the appropriate selling-price choice. It never silently combines different selling prices.

The default setting asks the cashier to choose. Staff still need to take the matching physical items from the shelf when using FEFO.

## 12. Read the expiry report

Open **Reports → Expiry report**:

- **Expiring soon:** remaining stock within the alert period, including the last day.
- **Expired:** batches with a date before today.
- **All expiry stock:** all remaining expiry-tracked batches, including undated stock that needs attention.

Filter by expiry dates, status, product, category, supplier, batch, purchase/source or product name/SKU/barcode. Remaining stock is selected by default; choose Include depleted batches to review exhausted records. Rows are ordered by earliest expiry. PDF and CSV downloads include every matching page. Cost values require cost permissions.

## 13. Read dashboard warnings

A small **Expiring soon** table appears below the normal dashboard sections. The default alert period is 45 days, and the default maximum is ten rows. Expired batches have a separate link. Depleted batches do not create warnings.

Statuses are Safe, Expiring soon, Urgent and Expired. The urgent period defaults to seven days. Settings control these periods. Calendar comparisons use the shop's time zone, normally Asia/Colombo. A product expiring today is not yet classified as expired.

## 14. Handle expired stock

Remove expired products from the selling shelf. The POS blocks expired batches by default. Product details distinguish physical, sellable, expired and unknown-date quantities. Low-stock alerts use sellable quantity for expiry-tracked products.

A manager exception is available only when Block expired sales is off, Allow manager confirmation is on, the account has `expiry.override_expired_sale`, and the user explicitly confirms the expired sale. Merely unticking Block expired sales does not allow ordinary cashiers to sell expired stock.

## 15. Write off expired stock

An authorized manager opens the **Expired** report, expands **Write off**, enters the quantity and reason, then reviews the confirmation. The confirmation shows the selected product, date and quantity; cost is shown only to users allowed to see it. Confirm **Remove stock & record expense**.

The system removes that batch's quantity and records an automatic expense under **Expired Stock Write-Off**, with an `EXP-WO-` reference. Five pieces costing Rs. 100 each create a Rs. 500 expense. Landed purchase costs are included where applicable. Selling prices are not used as the loss.

The expense contributes once to Profit & loss. It cannot be edited as a manual expense. Repeated submission of the same confirmation does not create another write-off. A new confirmation can remove a further remaining quantity. Bulk write-off is not included; review each batch separately.

## 16. Return a customer's product

For a verified return, the original sale allocations identify the exact batch. A normal Restock returns the quantity to that original batch, without changing another date's stock.

If the sold batch has expired by the return date, Restock is refused with: “This product has expired and cannot be returned to sellable stock.” Choose the existing **Write off** or **Return to supplier** action, with the required permission. These actions preserve the original stock provenance and financial return handling.

For a return without a bill, enter the date printed on the packaging. Restocking expiry-tracked products without a known date is refused by default. The optional unknown-expiry restock setting creates undated, **unsellable** inventory that must be identified before sale. It never invents a date. Known dates can also be retained for disposal/supplier-return records.

## 17. Return a delivery to the supplier

Start Purchase Return from the original purchase. It removes stock from that purchase's original stock layers and dates. It cannot borrow stock from another purchase just because the product or price matches. Supplier-return provenance keeps the source layer, quantity, cost and known date/batch.

## 18. Correct stock counts

For a positive stock adjustment, select an existing dated batch to use its date and prices, or enter the expiry date for a new batch. An existing batch selection preserves its date even while the global feature is off. A new row records the adjustment source rather than merging history.

For a negative adjustment, choose the batch to reduce when more than one exists. The system refuses an unspecified or insufficient batch. Stock movement history shows dated allocations while expiry tracking is enabled. Counting expired physical stock does not make it sellable.

Units continue to work normally: selling one box containing 12 pieces removes 12 pieces from the selected batch, not from an unrelated date.

## 19. Set permissions and receipt options

| Permission | What it allows |
|---|---|
| `expiry.view` | Open expiry reports and dashboard warnings |
| `expiry.manage_settings` | Change expiry settings |
| `expiry.override_expired_sale` | Explicitly confirm expired sales when policy allows |
| `expiry.writeoff` | Write off expired inventory |
| `expiry.view_cost` | See report cost values, together with View product costs |
| `purchase.expiry_edit` | Correct batch dates and edit purchase expiry |

Cashiers can select valid POS batches through their existing selling access. They do not need settings, write-off or cost permissions. New default Manager roles receive the standard management permissions; existing roles inherit newly introduced equivalent access without restoring previously revoked permissions. Expired-sale override is explicitly assigned and its policy is off by default.

**Show expiry date on receipt** is off by default. Turn it on to print the dates recorded by the sale allocations. A FEFO sale can show several dates. Changing product settings later does not change the completed sale's recorded dates.

## 20. Troubleshooting

| Problem | Check |
|---|---|
| No expiry menu | EXPIRE must be true; clear configuration cache; check account permissions. |
| No purchase date field | The product must have Maintain stock and Track expiry enabled. Reload after a server flag change. |
| Stock exists but POS says unavailable | Check expired, undated and depleted batches. Physical stock can exceed sellable stock. |
| A selected quantity is refused | That date/price has insufficient stock, or another checkout changed it. Refresh the cart. |
| Correct date is refused | A known dated batch has sale history. Use an adjustment to separate the remaining stock. |
| The dashboard seems empty | Check alert days, Show dashboard alerts, permissions and positive remaining quantities. |
| A return cannot be restocked | The original batch may now be expired, or an unverified return has no date. Use the controlled disposal/supplier workflow. |
| A write-off is refused | Check remaining quantity, expiry status, unit precision, permission and the confirmation token. Reload if stock changed. |
| Cost columns are missing | Both expiry cost and product cost permissions are required for report costs. |

Deployment should back up the database first. The additive migration does not duplicate stock, change existing quantities or guess existing expiry dates. Deploy the new code and built assets together, run migrations, then clear caches. Demo-cycle baselines are extended with the initially empty write-off table without resetting operational data.
