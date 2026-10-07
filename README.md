# Twinsofte Supermarket POS

A working Laravel 12 + PHP + Blade + MySQL supermarket POS with Tailwind CSS, vanilla JavaScript and Lucide icons. The workspace was empty at inspection, so this is a new application. All dashboard and report values come from saved transactions.

## Open this installation

App: **http://localhost/Super%20market/public/** (XAMPP Apache and MySQL must be running).

Sign in with username `admin` **or** email `admin@twinsofte.local`, using the same password. Its generated password is in the private, Git-ignored `.local-credentials.md` file in this directory. There is no hardcoded default password. The main database is `twinsofte_supermarket`; it contains configuration and the initial administrator, with no test products or transactions.

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

## Delivery details

See [complete delivery and verification notes](docs/DELIVERY.md), [database schema](docs/SCHEMA.md), [schema-only SQL](docs/schema.sql), [settings](docs/SETTINGS.md), [permissions](docs/PERMISSIONS.md), [route inventory](docs/routes.json), and [source file manifest](docs/FILES.md).

Thermal receipts support 80mm and 58mm with print-only layouts and measured roll lengths. Select the matching paper size, 100% scale, no margins, and disable browser headers/footers in the printer dialog. Physical printer calibration remains a device check.
