# Delivery: Twinsofte Supermarket POS

Delivered 7 October 2026 in `/Applications/XAMPP/xamppfiles/htdocs/Super market`.

## 1. Inspection and files

The supplied workspace was empty: there were no existing routes, controllers, database tables, views or features to preserve. A new Laravel 12 project was created. The application uses Blade, MySQL/MariaDB, Tailwind CSS, vanilla JavaScript and Lucide; it has no React/Vue SPA.

- `app/Http/Controllers/`: authentication/profile, dashboard, POS, generic CRUD, sales, purchases, registers, stock, settings and reports.
- `app/Http/Requests/`: validation and authorization for authentication, CRUD/filter inputs, purchases, stock adjustments, checkout, settings, registers, voids and reports.
- `app/Services/`: SaleService, PaymentChargeService, StockService, RegisterService, PurchaseService, ProfitLossService, SettingsService, ResourceService and ReportService.
- `app/Models/`: relationships, casts, audit and normalized business records; `app/Support/`: exact decimal money, resource definitions, audit and permission-aware navigation.
- `resources/views/`: reusable layout/icons, login/profile, actual dashboard, POS/payment/receipt flow, CRUD, purchases, sales, settings, registers and reports.
- `resources/js/`: debounced POS search, barcode input, cart, server quotes, payment and embedded receipt; purchase rows; sidebar, icons and form feedback.
- `resources/css/app.css`: responsive light emerald UI and print styles. `config/pos.php`: grouped setting definitions.
- `database/`: schema migrations, safe configuration seeders and optional example fees. `routes/`: web routes and private interactive administrator creation.
- `tests/`: financial/inventory/access regression tests and guarded browser fixtures. `.env.example`, lockfiles, `public/`, `.htaccess`, documentation and Vite configuration complete the install.

The [file manifest](FILES.md) lists source files. Dependencies, secrets, logs, cache files and built outputs are excluded from that manifest. `.local-credentials.md` is private and excluded from Git.

## 2. Migrations

Laravel's normal users/sessions, cache and queue migrations accompany five POS migrations:

| Migration | Effect |
| --- | --- |
| `2026_10_07_000100_create_pos_tables` | Core POS tables, foreign keys, money/quantity columns, indexes, users.role_id and users.active |
| `2026_10_07_000200_add_purchase_cost_history` | Adds purchase_items.previous_cost for purchase correction/void |
| `2026_10_07_000300_add_product_search_indexes` | Product active/name composite and MySQL FULLTEXT name index; sales status/sold_at composite |
| `2026_10_07_000400_allow_payment_rule_bearer_inheritance` | Nullable payment_charge_rules.charge_bearer lets a rule use its method's default payer |
| `2026_10_07_000500_add_usernames_to_users` | Unique users.username and collision-safe backfill for existing accounts; passwords are preserved |

Migrations do not seed sample sales/products or reset business settings. Changes to schema use migrations.

## 3. Tables and columns

New business tables: roles, permissions, permission_role, settings, units, categories, suppliers, customers, products, payment_methods, payment_charge_rules, registers, register_movements, sales, sale_items, sale_payments, expense_categories, expenses, purchases, purchase_items, stock_movements and audit_logs.

`users` gains a role foreign key, indexed active flag and unique username. Subsequent migrations add `purchase_items.previous_cost`, search/status indexes, and nullable rule bearer. The installed schema contains 31 tables including Laravel's framework tables. See [every column and type](SCHEMA.md) and [all indexes/foreign keys in schema-only SQL](schema.sql).

Money is DECIMAL(15,2), quantities DECIMAL(15,3), and configurable rule values DECIMAL(15,4). Sale/payment/item snapshots preserve original prices, costs, unit names, method metadata and applied charge rules even when configuration changes. Used records cannot be deleted through CRUD; deactivate them instead. Sales and purchases are voided, and manual expenses reversed, keeping history.

## 4. Routes

The [machine-readable route inventory](routes.json) contains the current 45 routes including framework health/storage routes. Application groups:

| Area | Endpoints |
| --- | --- |
| Authentication | GET/POST `/login`, POST `/logout`, GET/PUT `/profile` |
| Dashboard | GET `/` |
| POS | GET `/pos`, GET `/pos/products`, POST `/pos/quote`, POST `/pos/complete` |
| Sales | GET `/sales`, GET/PUT `/sales/{sale}`, GET `/sales/{sale}/receipt`, POST `/sales/{sale}/void` |
| Purchases | GET/POST `/purchases`, GET `/purchases/create`, GET `/purchases/{purchase}/edit`, GET/PUT `/purchases/{purchase}`, POST `/purchases/{purchase}/void` |
| Register | GET `/register`, POST `/register/open`, `/register/movement`, `/register/close`, GET `/register/{register}` |
| Settings | GET `/settings`, GET/PUT `/settings/{group}` |
| Reports | GET `/reports`, `/reports/{report}`, `/reports/{report}/export` |
| Stock | GET/PUT `/stock/{product}/adjust` |
| CRUD | GET/POST `/manage/{resource}`, GET `/manage/{resource}/create`, GET `/manage/{resource}/{id}/edit`, GET/PUT/DELETE `/manage/{resource}/{id}` |

Resource names are allowlisted: products, categories, units, suppliers, customers, expense-categories, expenses, payment-methods, payment-rules, users and roles. Reports are allowlisted: sales, purchases, expenses, profit, stock, product-sales, payments, register, cash, payment-charges, card-charges, qr-charges, bank-charges and audit. Unknown resource/report names return 404. Authenticated endpoints apply permission checks through middleware, controllers and Form Requests; the absence of a route-level permission string does not imply unrestricted access.

## 5. Permissions

There are 54 permissions with server-side enforcement. [Exact names and seeded role assignments](PERMISSIONS.md).

Administrator has all permissions. Manager has all except user/role management, including Settings; administrators can narrow this. Cashier receives dashboard.view, pos.access, sales.view/create, register.view/open/close and products.view. Cashier does not receive Settings or financial correction access. Categories/suppliers/customers inherit product permissions; expense categories inherit expense permissions; method/rule CRUD uses settings.payment_methods/settings.payment_rules. Stock settings use settings.pos and system settings use settings.business.

Self-disable/role changes, deleting the last active administrator, altering protected Administrator permissions, and granting permissions beyond the acting user's own access are blocked. Disabled accounts and password changes invalidate active sessions. Login attempts are rate limited.

## 6. Settings and defaults

[All 42 settings, labels and defaults](SETTINGS.md) are grouped into Business, POS, Receipt, Stock and System. Payment Methods and Payment Charge Rules are separate normalized CRUD screens. Units and user/role controls are also linked from Settings.

Business settings cover identity, contact, currency and logo. POS covers numbering, default customer/method, discounts, scanning/search and receipt behavior. Receipt covers width, optional fields and messages. Stock covers zero/negative selling. System covers timezone and formatting. Settings cache invalidates immediately and after transaction commit.

Logo uploads accept validated PNG/JPG/JPEG/WEBP up to 2 MB, use Laravel Storage, and support Change/Remove. Product images use equivalent validation. Default configuration seeds 10 units, 4 payment methods, general categories, a protected Payment Processing Charges category, 3 roles and permissions. No fixed products or fake chart transactions are seeded.

Example fees are explicitly editable: Cash none; Card 3%; QR bill greater than 5,000 at 10%; Bank Transfer none. Example rules inherit the method default, initially Customer. Set a method to Business to apply that mode across its inherited rules, or set an explicit rule override. Example seeding skips any method with existing rules and does not reset configuration on rerun.

## 7. Calculation and transaction behavior

`Money` uses Brick Math decimal arithmetic and HALF_UP rounding to two decimals. Each item extension is rounded before summing. Quantity math uses three decimals. The frontend preview rounds each line with integer cents, but Laravel independently recalculates all values and validates current stock, units, active methods, permissions and discounts before saving.

1. Base sale amount = rounded item subtotal minus invoice discount.
2. A rule matches its configured minimum (GTE or strictly GT) and optional inclusive maximum. Highest priority wins; equal-priority overlapping active ranges are rejected. Different priorities permit deliberate overrides.
3. Percentage charge = HALF_UP(base sale amount × configured percentage / 100). Fixed charge = configured amount rounded to cents. A 0% tier is allowed.
4. Rule bearer inherits the method default unless explicitly Customer/Business.
5. Customer pays: payable = base + fee; the fee is separately recorded and clearly printed. No business expense is created.
6. Business pays: payable = base; a linked automatic Payment Processing Charges expense is created. Internal fee amounts remain hidden from receipts unless enabled.

Examples covered by tests: 10,000 at 3% Customer → fee 300, payable 10,300, expense 0. Business → payable 10,000, expense 300. Strict QR greater-than-5,000 at 10% → 4,500 and exactly 5,000 fee 0; 6,000 fee 600.

Checkout locks the cashier register, products/units, method/rules and invoice counter in a single database transaction. Sale, items, payment, stock ledger, automatic expense and audit commit together or roll back together. A signed quote detects configuration/price changes between preview and confirmation; a UUID checkout token prevents duplicate submissions. Cash accepts tender above payable and records change. Noncash tender equals payable.

Register balances are computed from actual active sale payments: opening cash + cash customer collections + cash-in movements − cash-out movements − linked active cash manual expenses. Close records expected, actual and difference. Closed registers cannot be changed by later sale/expense corrections. Voiding an open-register sale restores stock, reverses automatic fee expenses and records actor/time/reason. Completed-sale edits are restricted to customer/reference/notes.

ProfitLossService centrally computes: discounted active sale revenue − original item cost snapshots − active manual expenses − active business-paid processing expenses = net profit. Customer-collected fees are excluded from product revenue and business expenses. Voided sales and reversed expenses are excluded. Product sales report item totals are before invoice-level discounts; the overall Sales/P&L reports use discounted invoice revenue.

Purchases update stock and cost transactionally; edits reverse original quantities before adding replacements. Voids restore previous cost when appropriate and require enough remaining stock to reverse the receipt. Initial stock and manual adjustments are logged. Integer units reject fractional stock/quantity; decimal units obey configured precision. No implicit unit conversion occurs.

## 8. Commands

Full fresh-install, upgrade-safe key handling, database setup and server instructions are in [README](../README.md). Required commands include:

```sh
composer install
php artisan migrate --seed
php artisan pos:admin owner@example.com
php artisan storage:link
npm ci
npm run build
php artisan optimize:clear
```

Configure `.env` and generate an application key **only when creating a new installation** before these commands. Optional example rules: `php artisan db:seed --class=PaymentExampleSeeder`. Production deploys need a document root at public/, correct storage/cache write permissions, built assets, real DB credentials and HTTPS. The present XAMPP installation also has a project-root redirect/protection for its existing folder URL.

## 9. Environment

The only added custom environment variable is `APP_TIMEZONE=Asia/Colombo`; the database-backed business timezone takes precedence after settings load. Standard values used: APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL, DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. `.env.example` documents normal Laravel session/cache/mail/queue values; no external payment provider or API key is required. This is payment recording and charge calculation, without a bank-terminal integration.

The current local database is `twinsofte_supermarket`, and APP_URL is the XAMPP public URL. APP_DEBUG is disabled. The generated initial password is in `.local-credentials.md`, excluded from version control and web access. That file is never included in schema/doc exports. Configure dedicated credentials for any customer deployment.

## 10. Verification

- **63 tests, 392 assertions passed on SQLite and on MySQL/MariaDB** after the username-login update. Username/email sign-in, case/whitespace normalization, legacy email submissions, disabled users, shared alias throttling and unique username management are covered. Includes all nine specified fee/quantity/P&L cases, fixed/prioritized/inherited rules, thresholds, zero tiers, exact rounding, tampered/stale/duplicate checkout, inactive methods/users, financial rollback, stock restoration, purchase corrections, expense reversal, closed-register protection, permissions/session security, upload type/size validation, audit redaction, streamed CSV exports, custom methods and immutable snapshots.
- Vite production build, Blade compilation, PHP formatting/lint, Composer validation and dependency audits completed. Composer and npm report no known dependency vulnerabilities after removing the unused concurrently development dependency.
- Browser login, logo change/removal, business/POS settings, barcode/quantity/discount/cash, customer- and business-paid card fees, QR below/exact/above threshold, purchase receipt and linked real reports were checked in an isolated QA database. A cash checkout with 1.250kg and discount 50 showed payable 950, paid 1,000, change 50 and an embedded receipt; Next sale cleared the cart.
- Dashboard, POS, business settings, product form, purchase form, payment-charge report and P&L were checked at **1920, 1366, 1024, 768, 430 and 390px**. No root horizontal overflow remained; wide tables scroll inside their containers.
- Receipt rendering verified loaded logo/contact, long names, 30 items, fractional quantities, discount and footer. Each 8.02 × 1.250 line rounded to 10.03, subtotal 300.90; discount 1 produced payable 299.90. Its measured roll page height was 1057mm. Cash/Card/QR and both fee-payer receipts were also covered. Print controls/app navigation are excluded by print CSS.
- Search benchmark on an isolated MySQL database with **10,037 products**, six runs per query with the first discarded: exact barcode median **1.52 ms** (one result), broad name term **72.48 ms**, interior name term **66.35 ms** (60-result cap), and no-match **9.42 ms**. These are local controller measurements, excluding network/browser latency.
- Final browser console checked after fixing the missing download icon. Local Apache login and asset/logo URLs were checked; `.env` is forbidden and nonpublic source paths are not served. Main database has zero products, sales, purchases or expenses; QA activity is separate in twinsofte_supermarket_ui. Temporary PHP development servers have been stopped; XAMPP Apache serves the operational app. The separate QA/test databases remain available for inspection and repeat testing.

Browser evidence: [test checkout receipt](screenshots/receipt-checkout.jpg), [long receipt](screenshots/long-receipt.jpg), and [operational dashboard](screenshots/dashboard.jpg). Test receipt screenshots contain deliberately synthetic QA records.

## 11. Limits and remaining device check

No known blocking application issue remained in the completed checks. **Physical 80mm/58mm printer output still needs verification on the customer's printer/driver**; a browser render cannot verify feed/cut behavior or hardware margins. Select the proper roll size, scale 100%, disable headers/footers and calibrate as needed. Barcode testing used keyboard-style scanner input; confirm the actual scanner sends Enter.

One payment method per sale is supported; split tender and refunds/returns are outside this implementation. Completed financial records use void/reversal rather than hard deletion, and financial sale correction is limited to the open register. The app operates in one configured currency with two-decimal money storage; changing currency labels does not convert historic amounts. Choose currency/timezone before live trading. Number-decimal settings control minimum display precision while retaining significant cents. Inventory is current-state reporting; reporting does not reconstruct historical stock valuation.

Routine operational backups, a dedicated production database user, a public/ document root and actual business/payment settings should be established when delivering this local installation to another customer environment.

## Username login update

Login accepts username or email and the existing password. The field is plain text, so entering `admin` no longer triggers browser email validation. Existing accounts receive a unique username from their email prefix; duplicate prefixes receive numeric suffixes. Username values normalize to lowercase and remain editable in Users. The administrator here can use `admin` or `admin@twinsofte.local`. The CLI accepts `--username` for new administrators. Apply the update elsewhere using `php artisan migrate` and `php artisan optimize:clear`. Both login modes were verified through the browser in the isolated QA environment; the current port-8002 login displays the updated form. [Login screenshot](screenshots/login.jpg).
