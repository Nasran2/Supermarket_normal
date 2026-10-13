# Optional barcode system

Barcode Labels integrates product barcodes, POS searching/scanning, individual and bulk stickers, purchase quantities, multiple selling prices, stock lots, units, saved layouts and print history. Browser printing uses vector SVG barcodes and physical millimetre sizes.

## Enable, disable and deploy

```dotenv
BARCODE=true
```

After changing the environment, run:

```bash
php artisan optimize:clear
```

On production installations that cache configuration, rebuild it after clearing:

```bash
php artisan config:cache
```

To disable the entire module, use `BARCODE=false` and clear/rebuild configuration. Product barcode controls, scanner instructions, barcode searching, print links, sidebar entries, settings and permission selections disappear. Barcode endpoints return 404 even for an Administrator. Normal name/SKU search and sales/purchases continue to work. Existing product barcode values, barcode formats, presets, jobs and historical permission assignments remain stored; enable the feature again to restore them. Disabled product edits cannot overwrite stored barcode values. Administrators cannot bypass the server feature flag.

Deploy a checkout containing this change with:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

This repository tracks built browser assets and its Composer vendor directory. If deploying from a source-only checkout or changing JavaScript/CSS, also run `npm ci` and `npm run build` before release. No barcode service, hardware integration or additional seed command is required. The migration installs permissions and three example presets. Do not run `migrate:fresh` against an existing store.

## Products and formats

Product Add/Edit has separate SKU and Barcode fields. SKU remains available when Barcode is disabled. Barcode is optional unless Settings → Barcode requires it. Scan or enter the printed code, choose its format and save. A barcode is unique across all products, including inactive products; duplicate validation identifies the owning product. Existing nullable `products.barcode` and its unique index are reused.

CODE128 is the default and accepts printable ASCII values up to 80 characters. EAN13, EAN8 and UPCA require exactly 13, 8 and 12 digits respectively, including a valid check digit. Product format is stored separately, so changing the global default does not change existing product formats. A preset can use each product's format or explicitly choose another compatible format; incompatible codes are rejected before printing.

Settings → Barcode controls automatic generation, automatic creation, numeric prefix, generated CODE128 length, required/optional entry, stock limits, default sticker quantity, large-job confirmation threshold, printer type and POS scanner preferences. EAN/UPC lengths and check digits follow the selected format. Generated identifiers use a locked sequence and skip occupied values. They are internal store identifiers; generating an EAN/UPC does not register it as a commercial product identifier.

Generate fills the product form; save the product to assign it. Automatic generation on creation applies only to a blank barcode, when both settings and the user's generation permission allow it. Manual entries are retained. Staff can generate missing codes from a label row or use Generate Missing Barcodes for selected products; this requires confirmation and `barcode.generate`. Manual entry requires `barcode.edit_product_barcode`. Neither flow silently replaces an existing assigned barcode for a user lacking edit permission.

## Scanning and searching

Use a keyboard-style scanner with Enter as its terminator in the POS search box. A complete matching barcode identifies the product. The existing POS stock selector still decides the selling price and expiry choice: one eligible option adds immediately; multiple options use the existing combined selector. A product barcode does not identify a stock lot. Existing unit conversions are preserved; this application has no unit-specific barcode column, so codes continue to identify products.

Barcode search also works in Products, category product lists, purchase selectors, stock adjustments, return selectors, expiry searches and label printing. When the server flag is disabled these matchers ignore the barcode column, even if an old POS search setting was `barcode`. The API does not return stored barcode values in disabled mode.

The Barcode Labels page keeps the catalogue hidden until you enter a name, SKU or barcode in Search. Select matching products and set the quantity for each. Selected rows and their quantities stay visible across searches and when Search is cleared; other results disappear. Category, status and stock filters narrow the search. Opening Print Label from a product or purchase keeps those requested products preselected.

## Daily individual printing

1. Open a product and choose Print Label, or choose its printer action in the product list.
2. Choose a selling price/unit. A product with several choices starts without a price selection; staff must choose explicitly.
3. Enter Quantity to Print. Quick buttons offer 1, 5, 10, 20 and 50.
4. Choose a Label Preset. The default is selected initially.
5. Inspect Preview, then Generate Labels for Print.
6. Review the product count, total labels, preset, paper width and labels per row. Confirm generation.
7. In the standalone print page choose Print N Labels and use the browser print dialog.

Generating a job records its contents; it does not alter inventory or prove that a physical printer delivered the stickers. Quantity must be a positive whole number. A job is limited to 10,000 labels; split large jobs, especially on slower machines. Jobs exceeding the configured large-job threshold also require server-validated confirmation.

## Bulk printing and quantities

Inventory → Barcode Labels → Print Labels lists up to 60 matching products at a time. Search by name/SKU/barcode and filter category, active/inactive, barcode presence or low stock. Select All Visible and Clear Selection are available. Selected rows remain selected when search filters change. Use Set Qty For Selected or a quick quantity button to apply a quantity to selected rows.

Each row has a selling price/unit/lot choice and independent quantity. Use Another Price / Lot for the same product to print, for example, 10 labels at Rs.130 and 15 at Rs.140. A user with individual printing access may print several price rows of the same product; printing several distinct products requires `barcode.print_bulk`.

Use Current Stock Qty explicitly copies available stock into the quantity field, rounding fractional quantities upward to a whole sticker. It never initiates printing. If quantity exceeds stock, the preview warns; Settings can block it. Repeated rows for the same price/unit/lot are counted together for this limit. Products without a barcode cannot produce an empty graphic or job; assign a code first.

The preview renders at most eight positions. The summary shows actual total labels and rows, including skipped positions. Five labels with two per row generate exactly three rows; no extra label is added to fill the final row.

## Print after a purchase

Open an active saved purchase and choose Print Labels for Purchased Items. The page preselects purchased products with their received base-unit quantities and exact purchase price/lot when that choice is still available. For example, 24 base pieces received suggest 24 stickers. Converted-unit purchases suggest base-piece stickers, not box-count stickers. Staff can change unit, price and quantity before generating a job.

If original purchase stock is no longer available, the price selector stays empty instead of substituting a different price. Choose an available option explicitly. Purchase access and bulk printing permission are required. Voided purchases do not provide this action.

## Expiry and batches

With `EXPIRE=true`, an expiry-tracked product offers each remaining stock lot separately, including price, date, batch and quantity. Select the exact lot. Show Expiry and Show Batch only print values from that selected lot. Expiry/batch values are not inferred from a product-wide barcode.

With `EXPIRE=false`, these controls, font controls, lot dates and label footer content are hidden. Existing lot and print-history data remain stored. Editing a preset while expiry is disabled preserves its hidden expiry configuration. Reprints hide expiry content while disabled and restore it when enabled.

## Presets and advanced setup

Open Barcode Labels → Presets to create, edit, duplicate, set default or delete a layout. You can also save/update it from the printing page. The current default cannot be deleted until another preset is made default. Editing the current default retains its status. Names are unique.

Three initial examples are installed: 49 × 25 mm - 2 Across, 38 × 25 mm - 1 Across and 50 × 30 mm - 2 Across. They are editable starting points, not fixed printer formats.

Advanced Label Setup contains:

- Sticker width and height, paper width and optional fixed paper height.
- Labels per row, vertical row gap and horizontal column gap.
- Four outside paper margins and four inside sticker padding values.
- Barcode height, content alignment and barcode alignment.
- Visibility of shop name, product name, price, SVG barcode, barcode number, SKU, unit, expiry, batch, custom text and secret code.
- Independent font size and bold for text fields.
- Product name one/two-line limit, ellipsis/wrap/shrink and automatic text fitting.
- Printed border, starting skipped positions and horizontal/vertical calibration offsets.
- Portrait/landscape: changing orientation swaps fixed paper width/height; for roll paper enter the width measured across the roll.

The UI uses centimetres; the backend stores canonical decimal strings in millimetres inside JSON, validated/calculated with Brick BigDecimal rather than storing binary floating-point measurements. For example, 4.9 cm is stored as `49.00` mm. Font sizes are entered in px and rendered with a stable 0.75 conversion to print points.

Required paper width is:

```
left margin + labels per row × sticker width
+ (labels per row - 1) × column gap + right margin
```

Calibration offsets must keep labels inside the paper. Impossible width, height, padding or minimum content layouts are rejected. Fixed-height sheets paginate by complete physical rows; auto height fits the roll job. Preview and print use the same sheet component and configuration.

Text may fit down to 6px. Product names are clamped/truncated or shrunk as configured. Barcode bars never shrink to fit crowded text; wide/long codes need a wider sticker. Graphics remain black on white with at least ten-module quiet zones on both sides (eleven on the left for EAN13). Minimum module widths are 0.19 mm for CODE128 and 0.264 mm for EAN/UPC. Barcode height must be at least 5 mm. The browser checks content fit and blocks its Print button if unresolved overflow remains.

Shop name and currency come from Business Settings. Secret Code is only user-defined custom/internal text; it never derives from or exposes product cost.

## Print history

Barcode Labels → Print History lists generated jobs, user, date, preset and label count. A job stores its original configuration plus product name, barcode, format, selected price/unit/expiry/batch, shop name and currency. Editing/deleting a preset or changing a product does not change a previous job. Reprinting needs print permission and either ownership of the job or history permission; bulk jobs additionally need bulk permission. Job history is generated-job history, not a physical printer status monitor.

## Printer calibration and troubleshooting

Settings → Barcode → Print Test Label prints four sample labels. Measure them with a ruler. Set the driver to the actual roll/sheet size, scale 100% / Actual Size, turn off Fit to Page and browser headers/footers. Start with zero offsets; adjust paper margins and small offsets only after confirming paper width, sticker size and gaps. For fixed sheets enter the actual paper height so page breaks align with rows.

A browser cannot control every driver, printer feed or unprintable margin. Use a physical test print on the intended device before production printing. If a browser clamps an unusually tall roll page, generate smaller jobs.

| Problem | Action |
| --- | --- |
| Module missing | Check BARCODE, clear configuration and verify role access. |
| Expiry controls missing | Check EXPIRE and whether that product tracks expiry. |
| Duplicate code | Reuse the owning product or assign a different barcode. |
| Invalid EAN/UPC | Enter the complete digit count with the correct check digit or use CODE128 for internal identifiers. |
| No barcode assigned | Generate/enter it with an authorized account before printing. |
| Price not selected | Choose a price/unit/lot for every selected row. |
| Purchase price not preselected | Its original stock option is depleted/unavailable; choose deliberately. |
| Labels exceed paper width | Reduce width/columns/gap/margins or increase physical paper width. |
| Content does not fit | Increase sticker height, enable text fitting, reduce fonts or hide optional content. |
| Long barcode rejected | Use a wider sticker or a shorter code. |
| Sticker sizing/drift wrong | Check Actual Size, driver paper size, gaps, margins, offsets and feed calibration. |
| Barcode unreadable | Check print density/resolution, quiet zones, sufficient height and actual hardware scanning. |
| Popup blocked | The browser falls back to opening the generated print page in the same tab. |

## Role permissions

`barcode.view`, `barcode.print`, `barcode.print_bulk`, `barcode.manage_presets`, `barcode.manage_settings`, `barcode.generate`, `barcode.edit_product_barcode`, `barcode.view_history`.

The migration installs records and Administrator access. Existing other roles are not silently expanded. Grant the required capabilities using Roles & Permissions. A new Manager created by the existing seeder follows its existing broad-manager policy; Cashier access remains opt-in. Barcode permission entries are hidden while disabled, but historical assignments are preserved.

## Verification commands

```bash
php artisan test --compact tests/Feature/BarcodeSystemTest.php
node --test tests/*.test.mjs
npm run build
```

The dedicated browser scripts require Playwright and Chrome plus the guarded disposable SQLite fixture. They must never run against the shop database. See `tests/Support/barcode-browser-bootstrap.php`, `tests/barcode-browser.test.cjs`, `tests/barcode-flags-browser.test.cjs` and `tests/barcode-stock-browser.test.cjs`. Test servers use BARCODE/EXPIRE overrides to verify enabled, disabled and re-enabled modes against the same disposable database. Browser fixtures use an open register so POS controls are available.

See `docs/BARCODE_DEVELOPMENT_REPORT.md` for files, integration details, deployment status and measured QA results.
