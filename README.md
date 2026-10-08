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

In the POS cart, tap **+** beside Customer to open **Add customer**. Name is required; phone, email and address are optional. **Save & select customer** saves an active customer and selects them for the current order without reloading or clearing the cart. Existing customer-create permission (`sales.create`) applies.

Enter **Old balance (due)** in the popup or customer create/edit page to record money already owed from earlier purchases. Customer pages show outstanding totals, balance filters, contacts and purchase history; the selected customer's due is also visible in the POS. This opening due stays separate from today's paid sale and register cash. Existing customers start at zero; run `php artisan migrate` on another installation to add the opening-due column.

## Product units

Products now have a primary stock unit and additional units with explicit conversions and optional selling prices. Load a matching preset from **Multiple units**, or add conversions directly. Stock stays in the primary unit; POS and purchase unit selectors convert each transaction automatically. Receipts keep the selected unit, while voids restore the saved primary quantity. Starter presets for pieces/dozen, kilograms/grams and liters/milliliters are installed here; `php artisan db:seed --class=UnitPresetSeeder` adds them elsewhere without replacing existing presets. See [multiple-unit workflow and verification](docs/PRODUCT-UNITS.md).

## Saved POS carts

The cart uses single-row items. Tap an item name to change its unit/price or add a fixed/percentage line discount; the cart displays its final amount. Drafts save automatically for each cashier in the same browser and survive refresh/navigation. **Clear order** or a successful payment clears the saved draft. Customer receipts show the final price and amount, while pricing/discount snapshots remain audited. See [cart workflow and checks](docs/POS-CART.md).

Use **Bill discount** below the subtotal for a fixed amount or percentage on the full bill after item discounts. The discount saves with the cart, updates the payment total and prints once on the receipt.

## Sidebar and stock adjustments

Expandable sidebar groups provide list/create links for existing modules and links to all 16 available reports, filtered by permission. Products → Stock adjustments supports selecting up to 100 products, Add/Remove/Set stock and optional primary-unit selling-price/cost changes. Batches can be viewed, filtered, printed, revised and reversed with their audit history preserved. See [workflow and validation](docs/STOCK-ADJUSTMENTS.md).

Sales now include labeled View, Edit, Delete/reversal, item Return and conditional Pay due actions. Returns and later payments update stock, invoice balances, register reconciliation and reports, with historical invoices retained. See [sales workflow](docs/SALES-WORKFLOW.md).

Invoice **Edit** now opens the POS screen for products, quantities, units, prices, line/bill discounts and payments. Review payment displays collection/refund adjustments; saving retains the invoice number and date with revision history. Unsaved edits reset on refresh or leaving and returning; new-sale carts continue to persist. See [eligibility and workflow](docs/SALES-WORKFLOW.md).

## Due checkout and product history

Use **Complete with due** for a partial payment or **No payment · leave bill due** for an unpaid sale. Select or add a named customer in the checkout popup; fully paid sales can continue as walk-in. The balance appears on the invoice and customer account, and successful due checkout clears the cart. Product profiles show stock movement history with clickable invoice numbers. See [workflow and checks](docs/POS-DUE-SALES.md).

## Stock prices and refreshed management views

Products can hold separately priced opening stock and deliveries. POS chooses an available selling price and consumes actual-cost stock FIFO within that price. Stock reports, returns and invoice revisions preserve those costs. Units support a changeable global default, and management forms now share a responsive emerald design. See [implementation, migration and verification report](docs/STOCK-PRICE-LAYERS.md).

## Purchase payments

Receive stock with **Paid in full**, **Partial payment**, or **Leave due**, and settle supplier balances later using **Pay due**. Purchases show paid/due totals, status and payment history. Cash payments update the open register automatically; older purchases use **Set balance** before tracking begins. See [purchase workflow and verification](docs/PURCHASE-PAYMENTS.md).
