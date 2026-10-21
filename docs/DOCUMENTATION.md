# Staff documentation and optional features

The signed-in **Documentation** library contains guides for selling, saved carts and discounts, invoice corrections, collections, accounts, purchases, returns, stock adjustments and price history, receipts, settings, reports and staff access. New guides also cover appearance and login customization, cheque settings and bank accounts, cheque receipt/issuance/clearance/return resolution, label printing, secret cost codes and demonstration mode.

## Environment visibility

Staff documentation reads the same Laravel configuration as the application. Change `.env`, then run `php artisan config:clear`; rebuild the configuration cache if your deployment uses it. Missing feature flags default to false.

| Environment setting | Documentation when enabled | When disabled |
| --- | --- | --- |
| `COLORS=true` | Names, colors, login text, icon/image, preview, reset and saving | Appearance guide and settings notes hidden |
| `CHEQUE=true` | Cheque workflows, Due/Hold, clearance/returns, bank accounts and alerts | Both cheque guides and all cheque notes hidden |
| `BARCODE=true` | Label printing/presets/history and scanning | Label guide, scanning instructions and label settings notes hidden; product-name search remains |
| `SCODE=true` and `BARCODE=true` | Secret cost-code guide and label notes | Cost-code guide and notes hidden if either flag is false |
| `EXPIRE=true` | Expiry guide and purchase, sale, return and label notes | Expiry guide, product step and all expiry notes hidden |
| `UNIT=true` | Units, conversions and related product instructions | Categories remain; unit instructions and conversion steps hidden |
| `hr_module=true` (or `HR_MODULE=true`) | Staff, attendance, leave, payroll and payments | HR guide hidden |
| `DEMO=true` | Practice store, protected settings and ten-day resets | Demo-mode guide and settings notes hidden |
| `TAMIL=true` | Tamil copies beside English | Tamil copies and indicator hidden |
| `SINHALA=true` | Sinhala copies beside English | Sinhala copies and indicator hidden |

English always remains available. Library cards, category lists, guide counts, search, related links and direct routes all use the same filtered collection. Hidden guides return 404. Search includes only visible text and translations. **Open this screen** still checks the existing screen permissions; enabling a feature does not grant permissions.

## Screenshots and written guides

Recorded screenshots contain optional controls. Walkthroughs appear only when units, barcodes and expiry are enabled and every visible step has matching frames. Otherwise complete written instructions appear without stale screenshots or empty animation controls. New guides use written steps rather than unrelated images. Written guides use the available width and support printing.

Step requirements and notes are filtered before numbering or search indexing. Frames stay attached to the original step index when a step is hidden.

## Sources and verification

Core guides are in `config/documentation.php`; new guides are in `config/documentation-updates.php`. `documentation-quantity.php` and `documentation-no-barcode.php` provide disabled-feature wording. Recorded frames and highlight geometry remain in `documentation-walkthroughs.php` and `documentation-screens.json`. `DocumentationService` filters content and builds search. Native copies are in `resources/documentation/ta.json` and `si.json`.

The feature tests cover all 256 combinations of the eight functional switches, restoration after toggling, hidden guide routes, filtered settings notes/search, language coverage and frame geometry:

```sh
php artisan test --compact tests/Feature/DocumentationFeaturesTest.php tests/Feature/DocumentationLanguagesTest.php tests/Feature/DocumentationRoutingTest.php
```

Static Markdown files in `docs/` are installation/development references. They describe all flags for administrators and are not rendered as staff guides.
