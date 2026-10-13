# Twinsofte Supermarket POS

A working Laravel 12 + PHP + Blade + MySQL supermarket POS with Tailwind CSS, vanilla JavaScript and Lucide icons. The workspace was empty at inspection, so this is a new application. All dashboard and report values come from saved transactions.

## Open this installation

App: **http://localhost/Super%20market/public/** (XAMPP Apache and MySQL must be running).

Sign in with username `admin` **or** email `admin@twinsofte.local`, using the same password. Its current local password is in the private, Git-ignored `.local-credentials.md` file in this directory. There is no hardcoded default password. The main database is `twinsofte_supermarket`; it contains configuration and the initial administrator, with no test products or transactions.

Usernames can be added or changed under **Users**. They are unique, case-insensitive and allow letters, numbers, dots, underscores and hyphens. Upgrades assign existing accounts names based on their email prefix, adding a numeric suffix when needed; passwords remain unchanged.

Start in **Settings** to enter your business details, upload a logo and review payment fees. Then add suppliers/products, receive purchases, and open a register before selling. The example Card 3% and QR greater-than-5,000 10% rules are enabled here and can be changed or deactivated.

## Install on another machine

Requirements: PHP 8.2+, Composer 2, MySQL/MariaDB with InnoDB and FULLTEXT support, Node.js 20.19+ or 22.12+, and a web server. PHP needs Laravel's standard extensions, including PDO MySQL, mbstring, fileinfo, XML and OpenSSL.

```sh
composer install
cp .env.example .env  # only for a NEW installation; preserve an existing .env
php artisan key:generate  # only once for a NEW installation
```

Create a dedicated database and set the normal `DB_*` values in `.env`. Set `APP_URL` to the actual application URL. For example, locally:

```sql
CREATE DATABASE twinsofte_supermarket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```sh
php artisan migrate --seed
php artisan pos:admin owner@example.com --username=owner --name="Store Administrator"
php artisan storage:link
npm ci
npm run build
php artisan optimize:clear
```

`pos:admin` prompts privately for a password of at least 10 characters. It refuses to overwrite an existing account. Seeders add missing configuration without resetting existing settings or rules. Example fees are optional on a new installation:

```sh
php artisan db:seed --class=PaymentExampleSeeder
```

Use a web-server document root pointing at **public/**. For local development:

```sh
php artisan serve --host=127.0.0.1 --port=8010
```

Run `npm run dev` in a second terminal only when developing frontend assets. The built assets are sufficient for normal use. Ensure the PHP/web-server user can write **storage/** and **bootstrap/cache/**. This local XAMPP installation already has scoped permissions for its `daemon` user.

For deployment set `APP_ENV=production`, `APP_DEBUG=false`, the correct `APP_URL`, dedicated database credentials, and HTTPS. Back up the database and uploaded files before upgrading. Never regenerate an established application's key during an upgrade.

## Verify

```sh
php artisan test --compact
npm run build
php vendor/bin/pint --test
composer audit
npm audit
```

The default test suite uses SQLite in memory. To run the same tests on MySQL, create a **separate, disposable** test database; the tests rebuild its schema:

```sh
DB_CONNECTION=mysql DB_DATABASE=twinsofte_supermarket_test php artisan test --compact
```

Never target the operational database with test commands. Browser fixture setup is guarded to the separate `twinsofte_supermarket_ui` database; see `tests/Support/browser-bootstrap.php`.

## POS payments

The selling screen uses a full-width workspace with hamburger navigation, a right-hand cart and a touch payment popup. A sale can use one method or split across Cash, Card, QR and other configured methods, including repeated entries such as two card payments. The POS stays locked until its opening-register popup is completed; closing shows the sales, method totals, customer/business fees and cash reconciliation in a popup. Sixteen sample products with audited opening stock are installed here; `php artisan db:seed --class=SampleProductSeeder` adds them on another installation without resetting existing stock. See [cashier instructions, fee behavior and verification](docs/POS-SPLIT-PAYMENTS.md).

## Delivery details

See [complete delivery and verification notes](docs/DELIVERY.md), [database schema](docs/SCHEMA.md), [schema-only SQL](docs/schema.sql), [settings](docs/SETTINGS.md), [permissions](docs/PERMISSIONS.md), [route inventory](docs/routes.json), and [source file manifest](docs/FILES.md).

Thermal receipts support 80mm and 58mm with print-only layouts and measured roll lengths. Select the matching paper size, 100% scale, no margins, and disable browser headers/footers in the printer dialog. Physical printer calibration remains a device check.

In the POS cart, tap **+** beside Customer to open **Add customer**. Name is required; phone, email and address are optional. **Save & select customer** saves an active customer and selects them for the current order without reloading or clearing the cart. Customer creation requires `customers.create`; POS access is checked separately.

Enter **Old balance (due)** in the popup or customer create/edit page to record money already owed from earlier purchases. Customer pages show outstanding totals, balance filters, contacts and purchase history; the selected customer's due is also visible in the POS. This opening due stays separate from today's paid sale and register cash. Existing customers start at zero; run `php artisan migrate` on another installation to add the opening-due column.

## Product units

Products now have a primary stock unit and additional units with explicit conversions and optional selling prices. Load a matching preset from **Multiple units**, or add conversions directly. Stock stays in the primary unit; POS and purchase unit selectors convert each transaction automatically. Receipts keep the selected unit, while voids restore the saved primary quantity. Starter presets for pieces/dozen, kilograms/grams and liters/milliliters are installed here; `php artisan db:seed --class=UnitPresetSeeder` adds them elsewhere without replacing existing presets. See [multiple-unit workflow and verification](docs/PRODUCT-UNITS.md).

## Saved POS carts

The cart uses single-row items. Tap an item name to change its unit/price or add a fixed/percentage line discount; the cart displays its final amount. Drafts save automatically for each cashier in the same browser and survive refresh/navigation. **Clear order** or a successful payment clears the saved draft. Customer receipts show the final price and amount, while pricing/discount snapshots remain audited. See [cart workflow and checks](docs/POS-CART.md).

Use **Bill discount** below the subtotal for a fixed amount or percentage on the full bill after item discounts. The discount saves with the cart, updates the payment total and prints once on the receipt.

## Sidebar and stock adjustments

Expandable sidebar groups provide list/create links for existing modules and links to all 16 available reports, filtered by permission. Products → Stock adjustments adds products directly from search or barcode scans, with Enter moving between quantity entry and search. It supports up to 100 products, Add/Remove/Set stock and optional primary-unit selling-price/cost changes. Batches can be viewed, filtered, printed, revised and reversed with their audit history preserved. See [workflow and validation](docs/STOCK-ADJUSTMENTS.md).

Sales now include labeled View, Edit, Delete/reversal, item Return and conditional Pay due actions. Returns and later payments update stock, invoice balances, register reconciliation and reports, with historical invoices retained. See [sales workflow](docs/SALES-WORKFLOW.md).

Invoice **Edit** now opens the POS screen for products, quantities, units, prices, line/bill discounts and payments. Review payment displays collection/refund adjustments; saving retains the invoice number and date with revision history. Unsaved edits reset on refresh or leaving and returning; new-sale carts continue to persist. See [eligibility and workflow](docs/SALES-WORKFLOW.md).

## Due checkout and product history

Use **Complete with due** for a partial payment or **No payment · leave bill due** for an unpaid sale. Select or add a named customer in the checkout popup; fully paid sales can continue as walk-in. The balance appears on the invoice and customer account, and successful due checkout clears the cart. Product profiles show stock movement history with clickable invoice numbers. See [workflow and checks](docs/POS-DUE-SALES.md).

## Stock prices and refreshed management views

Products can hold separately priced opening stock and deliveries. POS chooses an available selling price and consumes actual-cost stock FIFO within that price. Stock reports, returns and invoice revisions preserve those costs. Units support a changeable global default, and management forms now share a responsive emerald design. See [implementation, migration and verification report](docs/STOCK-PRICE-LAYERS.md).

## Purchase payments

Enter the amount given to automatically mark a purchase Unpaid, Partial, or Paid and show any excess as change. Settle supplier balances later using **Pay due**. Add multiple shipping/other charges as linked expenses (default), or distribute them into incoming product cost. New references use **pur-YYYYMM-0001** and **INV-YYYYMMDD-00001**. Purchases show paid/due totals, status and payment history. Cash payments update the open register automatically; older purchases use **Set balance** before tracking begins. See [purchase workflow and verification](docs/PURCHASE-PAYMENTS.md).

## Account histories and report PDFs

Payment method views open on Today with saved bill-level charges, cash received/change, later due collections, account payments and refunds. Quick ranges and inclusive custom dates drive the collection, charge, refund and net-collection cards. The payment-method directory shows the same filtered totals across all methods. Voided sales are excluded and customer invoice allocations are counted once.

Category View shows its products with search and status filters. Supplier lists show dues; supplier accounts include filtered purchase/payment histories and PDF/CSV ledgers with opening and running balances. Customer **Collect Payment** is available in Actions only when due exists. Expense forms can create and select a category using the adjacent plus button without losing the draft. Product create/edit forms also have plus buttons beside Categories and Supplier: save a new entry in the popup to select it without losing product details or previously selected categories. Each button requires its corresponding create permission.

All 16 reports offer formatted PDF downloads with company letterhead from Business settings, applied filters, report-specific tables or statements, totals and page numbers. Downloads include all matching records across screen pages. See [account/report workflow and validation](docs/ACCOUNT-REPORTS.md).

## Larger sample catalog

Run `php artisan db:seed --class=BulkSampleProductSeeder` to add 120 clearly labelled sample products (`SAMPLE-001` through `SAMPLE-120`) with unique EAN-13 barcodes across six categories. Samples include low/out-of-stock products, kilogram quantities, same-price deliveries and different-price deliveries. Stock is recorded through the normal stock-layer and movement services. Rerunning skips existing sample SKUs without replenishing stock or overwriting edits; existing products are untouched. POS initially shows up to 60 matches, so search or select a category to find the remaining products. Product management uses pagination.

### Granular team access

Roles & permissions now lists independent dashboard cards, module CRUD and transaction/report functions with descriptions, search and selection counts. Administrator receives full access automatically. Existing role access is preserved by the permission upgrade migration, and later revocations are respected. See [permission reference](docs/PERMISSIONS.md).

Roles can choose All sales, Sales from the same role, or Only this person’s sales. This setting applies to invoice access, sales totals, related history and report downloads, while action permissions remain separate. Administrator always has all-sales access. The upgrade preserves existing role scopes; new roles default to own sales. See `docs/PERMISSIONS.md` for scope and accounting details.

## Optional human resources

Set `hr_module=true` in `.env` and run `php artisan optimize:clear` to show **Human resources**. `false` hides every HR page, report and role-editor permission, and blocks direct HR URLs while preserving history. Administrator gets all HR access automatically; assign individual permissions to other roles. Customers stay in the existing customer module.

HR includes staff intake/exit/rehire and optional user accounts, departments/job titles/shifts/holidays, attendance/overnight shifts/overtime, paid and unpaid leave approvals, monthly/daily payroll with allowances/deductions, partial salary payments/advances/reversals, staff ledgers and six filtered PDF/CSV reports with company letterhead. Approved payroll records salary cost once and locks attendance; cash payouts update register reconciliation. See [HR setup, payroll policies and verification](docs/HR-MODULE.md).

## Returns and exchanges

Sales and purchase returns now use four-step wizards with historical stock allocations, due-first settlement, linked exchange bills, write-offs and supplier claims. Settings, permissions, cancellation reversals, 80mm credit notes and account/register/report integration are included. See [workflow, safeguards and verification](docs/RETURNS-EXCHANGES.md).

Sales Return also supports **Return Without Bill**, with optional customer/history matching, mixed verified and unverified items, explicit credit prices and estimated cost sources, independent stock actions, exchanges and due/refund settlement. With Bill remains the default. See [policies, accounting and verification](docs/NO-RECEIPT-RETURNS.md).

## Staff documentation

**Documentation**, below Settings in the sidebar, opens a searchable staff guide with 21 topics and 99 plain-language steps. Each topic has a separate page with walkthroughs made from real application screens, zoomed control highlights, pause/replay and individual step controls, a larger viewer, printable instructions, and permission-aware links to the relevant screen. Examples use fictional data and never create transactions. Content is maintained in `config/documentation.php`; frame sequences and measured control coordinates live in `config/documentation-walkthroughs.php` and `config/documentation-screens.json`. PNG captures in `public/help-assets/screens` use only an isolated fictional store. Refresh the relevant captures and coordinates when a screen layout changes. Animations run locally without video downloads or external services. Reduced-motion preferences disable autoplay and visual motion. All signed-in active staff can read guides even when they cannot access Settings.


Products support an optional default discount (`NONE`, `AMOUNT` or `PERCENT`) and `track_stock`, which defaults to true for existing and new products. Fixed defaults apply once to the entire cart line, regardless of quantity; percentage defaults apply to that line's subtotal. The server resolves presets from the product and clamps fixed defaults to the line amount. Approved presets do not require the cashier's manual-discount permission or consume the manual-discount limit; the global Discounts switch still applies. Manual line changes replace the preset. Sales retain the chosen discount and stock-tracking state so returns and voids use the original transaction.

With Maintain stock off, sales and purchases retain amounts and cost snapshots without creating inventory layers or quantity movements. These products are excluded from low-stock alerts and inventory reports. Existing stock must be adjusted to zero before disabling tracking. Purchase charges for untracked products must use Expense treatment. Edit a product to choose its default discount or change stock tracking; ordinary products keep tracking with no discount.

Business settings include a **Show on bill** checkbox beside every business detail, including WhatsApp, registration number, logo and currency. The same choices apply to sales receipts and return slips. Receipt settings also let you show or hide the footer and custom message without deleting their text. Save changes, then refresh any already-open bill to use the updated choices.

Sales receipts show each applied line discount below its product, including the percentage when applicable and the actual amount deducted from the entire line. In Settings → Receipt, **Show discount beside each item** controls this display and is enabled by default.

To print two logos side by side, upload separate pictures under **Business logo** and **Second bill logo** in Settings → Business, tick **Show on bill** for each and save. PNG, JPG and WEBP pictures up to 2 MB each are supported. Either logo can be replaced, hidden or removed independently. A single visible logo remains centred. Both sales receipts and return slips use this layout.

Expense history includes **Edit expense** beside each active manual expense reference. Saving changes updates the existing entry and its audit history; cash expense changes recalculate the open register total. Automatic and reversed expenses remain protected, and cash expenses require their original register to be open.

With barcode scanning enabled, a complete barcode or SKU adds the matching active product to the POS cart after input settles, with or without an Enter suffix. Repeat scans increase quantity. Name searches stay in the catalogue; stock and multiple-price checks still apply. Enter scans use separate queued lookups so rapid scans cannot use another search’s results. Completed or cleared orders reset the search and category for the next customer.

Audit reports show labelled Before/After values, highlight changed fields, and keep unchanged values and metadata under Other details. Long entries can expand additional changed fields. Yes/No switches, monetary amounts, dates, nested records, and older encoded settings snapshots are formatted for reading; original audit records remain intact. PDF and CSV exports use the same readable labels.

Demo mode is controlled by `DEMO=true` in `.env` (default: `false`). Run `php artisan migrate --force` and `php artisan config:clear` after deployment; rebuild the configuration cache if the host uses it. Business details, all Settings groups, payment methods/rules, users, roles, passwords, units/presets and HR setup/account changes are read-only, including direct HTTP submissions by administrators. Normal sales, purchases, returns, product/customer/supplier changes and expenses remain usable. Pages and login show a DEMO notice and the next reset time; receipts, return slips, ledger prints and every report/PDF page carry a light DEMO watermark. Turning demo mode off removes these labels and restrictions and prevents resets.

Set up a **separate demo database** with the business configuration, accounts and starting sample data before enabling demo mode. The first request or `php artisan demo:reset` captures that starting business dataset as a private database baseline. Every ten days it restores that baseline, including inventory quantities/layers, transactions, customers, suppliers, categories, expense categories, document counters, audit history and HR activity. It preserves configured business details/logos, payment configuration, units/presets, accounts/passwords/permissions, HR options and infrastructure. It restores the original sample records rather than inventing new login credentials or running `migrate:fresh`. Existing example transactions in the starting dataset reappear after each reset. Baseline snapshots contain all starting business rows, so prepare a small fictional dataset before enrollment. Uploaded images are retained on disk; initial product image paths remain valid. Browser POS drafts are separated by demo cycle so an old cart is not reused after reset.

Laravel schedules `demo:reset` every minute and checks the ten-day deadline before changing anything. Configure the hosting scheduler to run `php artisan schedule:run` every minute (for cPanel, use the host's PHP path and the absolute application path):

```cron
* * * * * cd /absolute/path/to/application && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

An overdue demo also resets on its next web request if the scheduler was missed; a submitted form that triggers reset is rejected with a reload message. A shared file-cache lock coordinates web requests and the scheduler on one application host. Restores use a database transaction, preserve foreign-key enforcement afterwards, and advance the reset date only on success. Run all demo instances against the same writable `storage/framework` directory. Dates are stored in UTC and displayed in the configured business timezone. After changing the application's database schema or its starting dataset, the installation owner must deliberately recreate the `demo_cycles` baseline in the demo database; mismatched tables/columns fail before destructive changes. `php artisan demo:reset` is safe when `DEMO=false` and never changes data in that mode.


### Optional expiry tracking

Expiry tracking is off by default (`EXPIRE=false`). Set `EXPIRE=true`, run `php artisan migrate --force`, `php artisan optimize:clear`, and `npm run build`, then enable **Track expiry** for each relevant product. Batch dates extend the existing stock layers; quantities are not duplicated. Disabling the feature hides its controls and keeps all recorded dates. See [Expiry tracking](docs/EXPIRY_TRACKING.md) for staff instructions, permissions, FEFO, returns and write-offs.

Implementation files, verification results and local/hosting deployment details are recorded in [Expiry implementation report](docs/EXPIRY_IMPLEMENTATION.md).


### Optional Barcode Labels

Set `BARCODE=true` and run `php artisan migrate` followed by `php artisan optimize:clear` to enable barcode generation, scanning, labels and presets. `BARCODE=false` hides the module and preserves stored data. See [Barcode System](docs/BARCODE_SYSTEM.md) for setup/printing/permissions and [Development Report](docs/BARCODE_DEVELOPMENT_REPORT.md) for implementation and QA results.
