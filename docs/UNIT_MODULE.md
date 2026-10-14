# Optional Unit module

`UNIT` controls units and multiple-unit conversions throughout Twinsofte POS. Application code reads `config('features.unit')`; environment access is confined to `config/features.php`. The configuration fallback is `false`. The supplied `.env.example` enables the existing module with `UNIT=true`.

## Enable or disable

Set one uppercase environment key:

```dotenv
UNIT=true
```

or:

```dotenv
UNIT=false
```

Then run:

```sh
php artisan optimize:clear
```

If your production deployment caches configuration, regenerate it after changing the environment:

```sh
php artisan config:cache
```

Rebuild assets after installing these code changes with `npm run build`. A subsequent environment toggle does not require rebuilding JavaScript: the current setting is delivered in each page's `data-units` attribute. Reload open browser tabs after toggling.

## Default internal quantity basis

The existing `units.default_slot` unique column identifies the single default unit. No numeric unit ID is assumed. `DefaultUnitService::resolve()` uses the current active default. If legacy data has no default, it finds Piece by `pcs` or its name, or creates `Piece (pcs)` with whole quantities. It assigns the unique default slot in a transaction and handles concurrent first use. The database seeder uses the same idempotent resolver.

With units enabled, an authorized administrator can choose another active default in the Units directory. With units disabled, that directory and its actions return 404. The internal resolver still honors the stored default. A missing default is repaired when needed; toggling alone does not change product relationships.

## Products and quantities

With `UNIT=true`, primary selection, additional conversions, presets, unit prices, and unit labels work as before. This application stores the primary relationship as `products.unit_id`.

With `UNIT=false`:

- Creation accepts no unit field and assigns the resolved default on the server. Submitted unit/conversion values cannot override that choice.
- Editing preserves the existing primary relationship and conversion records, including forged submissions or omitted fields.
- Existing kilogram/liter/box products continue to use their original stock basis and decimal rules. A stock quantity of 20 kilograms remains internally 20 kilograms; the interface displays `20`.
- New Piece products require whole quantities. The existing global `quantity_decimals` limit also remains in force.
- Unit fields, helper text, selectors, suffixes and conversion sections disappear. Prices and stock remain available.

`Units::quantity()` centralizes presentation. `UnitService` exposes default resolution and product quantity formatting. `ProductUnitService::publicOptions()` returns only the primary option with blank names in quantity mode; IDs and decimal rules can remain in payloads for internal frontend state. Persistent transaction snapshots retain their actual unit names.

## POS, purchases, stock and returns

The POS shows prices and quantities without suffixes. Multiple selling prices and expiry choices still work. Cart quantities use the product's existing decimal rule. Backend checkout ignores browser unit selection while disabled.

New purchases and stock adjustments resolve the product's primary unit without a browser unit field. Remaining form fields use the available width. Purchase edits populate primary quantities, purchase cost before landed charges, and primary selling price; allocated shipping costs are not added twice.

Sales details, purchase details, stock layers, movement history, category products, dashboards, customer/supplier screens, return details and receipts omit unit names. Historical quantities remain based on their stored transaction snapshots.

Invoice-linked returns restore or remove the original saved primary quantity, including historical box sales/purchases. Same-product exchanges use the original transaction basis through a trusted server-only path; a browser cannot enable historical conversions. Unlinked returns and other-product replacements use the primary basis while disabled. Return allocations, FIFO/FEFO, financial settlement and audit history remain intact.

## Permissions and route protection

The application uses its existing `units.*` and `unit-presets.*` permission names. The feature also recognizes historical `multiple_units.*` names. When disabled, these permissions disappear from role forms, role details, permission counts and navigation. Saving an existing role preserves its stored hidden grants; new attempts to assign disabled grants are rejected. `User::hasPermission()` rejects disabled unit permissions even for administrators.

`EnsureUnitFeatureEnabled` protects the default-unit action. `GuardUnitResources` protects every shared CRUD action for Units and Multiple units before form validation. Nonexistent alternate unit paths remain 404. Feature access takes precedence over stored permission grants.

## Reports, printing and integration

Stock, stock-layer and product-sales report headers and mapped rows omit Unit/Primary unit columns together. HTML, CSV and PDF use the same filtered report data. Expiry exports similarly omit units. There is no separate Excel exporter in this application; CSV files opened in Excel follow the same rule. Audit presentation hides unit relationships and conversion snapshots without deleting stored audits.

80mm/58mm sale and return receipts omit unit text. Receipt `show_unit` settings are hidden and preserved when edited with the module off.

With `BARCODE=true`, label printing still works. Unit content/typography controls disappear, price choices omit units, and new or historical print jobs render without unit text. Existing preset unit settings remain stored and return when enabled again.

`EXPIRE` and `CHEQUE` remain independent. Expiry dates, batches, price layers and cheque actions continue to use their own feature flags and permissions. These combinations are supported:

```dotenv
BARCODE=true
EXPIRE=true
CHEQUE=true
UNIT=false
```

```dotenv
BARCODE=false
EXPIRE=false
CHEQUE=false
UNIT=false
```

Help guides use quantity-only written instructions when disabled. Original walkthrough screenshots contain unit navigation and controls, so their player is omitted in this mode. Enabling units restores those walkthroughs.

## Data preservation

No destructive migration is required. Units, product relationships, conversion presets, conversions, stock allocations, historical sales/purchases, barcode configurations and permission records remain stored. Switching `true → false → true` restores the existing information. Explicit transaction edits continue to follow the application's normal inventory/financial rules; changing the flag itself never rewrites historical records.

## Troubleshooting

- If old controls remain visible, clear cached configuration and compiled views, regenerate production configuration, and reload the page.
- A default-unit selection error should not occur for new products in quantity mode. Check that all module changes are deployed and the default-slot migration is present.
- Fractional quantities are still rejected for whole-count products. Disabling unit presentation does not change their decimal rules.
- A cart saved before a toggle can contain unavailable choices. Review quantities/prices or remove unavailable items before checkout; the server never trusts a stale conversion selection.
- Do not repair stock by replacing existing product relationships with Piece. Inspect stock movements and recorded allocations instead.

## Verification

`tests/Feature/UnitModuleTest.php` checks enabled/disabled routes and UI, forged input, default recovery, permission preservation, decimal stock, purchases/adjustments, original conversion returns/exchanges, no-receipt returns, safe purchase editing, CSV/PDF reports, barcode presets, expiry and cheque independence, and help presentation. Existing enabled-unit and inventory regression tests remain applicable.

```sh
php artisan test --filter=UnitModuleTest
npm run build
node --test tests/*.test.mjs
```

For browser checks, use a **disposable SQLite file** whose path contains `twinsofte-unit-browser`. Never use the shop database. Bootstrap it with `tests/Support/unit-browser-bootstrap.php`, then start local servers against that file: port 8139 with `UNIT=false` and port 8140 with `UNIT=true`, both with `BARCODE=true EXPIRE=true CHEQUE=true DEMO=false`. Also start port 8141 with all four feature flags false to check the basic POS. Set `DB_CONNECTION=sqlite`, `DB_DATABASE` to that file, `DB_URL=` and `CACHE_STORE=array` for the fixture and every test server. Run `tests/unit-module-browser.test.cjs` with Playwright available. The script checks visible UI, product creation/editing, decimal purchase, price/expiry selection, cart editing/checkout, historical exchange, barcode previews, desktop/tablet/mobile layouts restoration after enabling, and checkout with all optional modules disabled. Use a fresh fixture for each browser run.
