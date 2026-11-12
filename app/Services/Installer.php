<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleProductSeeder;
use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Throwable;

class Installer
{
    private const CONNECTION = 'twin_install';

    public function locked(): bool
    {
        return is_file(app()->environmentFilePath()) || is_file($this->stateFile('installed'));
    }

    public function matchesPin(#[\SensitiveParameter] string $pin): bool
    {
        $configured = config('installer.pin');
        $expected = filled($configured) ? hash('sha256', (string) $configured) : 'a815067c3d78312c270b68cbf0e751ca8f7ff33c3984e3dff6f21f749ecc8141';

        return hash_equals($expected, hash('sha256', $pin));
    }

    public function bootstrapKey(): string
    {
        return $this->synchronized(function () {
            $path = $this->stateFile('session-key');
            if (! is_file($path)) {
                $this->writeAtomically($path, $this->newKey());
            }

            return trim(file_get_contents($path));
        });
    }

    public function install(#[\SensitiveParameter] array $data): void
    {
        $this->synchronized(function () use ($data) {
            if ($this->locked()) {
                throw ValidationException::withMessages(['setup' => 'Setup is locked. Reload the page to continue.']);
            }

            $env = $this->environment(base_path('.env.example'), $this->moduleValues($data) + [
                'APP_NAME' => $data['shop_name'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => false,
                'APP_KEY' => $this->newKey(),
                'APP_URL' => rtrim($data['app_url'], '/'),
                'SESSION_SECURE_COOKIE' => str_starts_with($data['app_url'], 'https://'),
                'DEMO' => false,
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $data['db_host'],
                'DB_PORT' => (string) $data['db_port'],
                'DB_DATABASE' => $data['db_database'],
                'DB_USERNAME' => $data['db_username'],
                'DB_PASSWORD' => $data['db_password'] ?? '',
            ]);

            $this->writeAtomically(app()->environmentFilePath(), $env, function () use ($data) {
                $this->initializeDatabase($data);
                $this->clearConfigurationCache();
            });
            $this->writeAtomically($this->stateFile('installed'), "installed\n");
        });
    }

    public function reconfigure(array $data): void
    {
        $this->synchronized(function () use ($data) {
            $path = app()->environmentFilePath();
            if (! is_file($path)) {
                throw ValidationException::withMessages(['setup' => 'Restore the installation’s .env file before updating its modules.']);
            }

            $this->writeAtomically($path, $this->environment($path, $this->moduleValues($data)), $this->clearConfigurationCache(...));
        });
    }

    private function initializeDatabase(#[\SensitiveParameter] array $data): void
    {
        $original = config('database.default');
        config(['database.default' => self::CONNECTION, 'database.connections.'.self::CONNECTION => $this->databaseConfig($data)]);

        try {
            $stage = 'Connection check';
            $connection = null;
            $driver = config('database.connections.'.self::CONNECTION.'.driver');
            $mysql = in_array($driver, ['mysql', 'mariadb'], true);
            if ($mysql && ! extension_loaded('pdo_mysql')) {
                throw ValidationException::withMessages(['db_database' => 'The PHP MySQL driver (pdo_mysql) is missing. Enable it for the PHP version used by your website.']);
            }

            $connection = DB::connection(self::CONNECTION);
            $schema = $connection->getSchemaBuilder();
            $database = $schema->getCurrentSchemaName();
            $tables = $schema->getTables($database);
            if ($tables || $schema->getViews($database)) {
                $myisam = collect($tables)->contains(fn ($table) => strcasecmp($table['engine'] ?? '', 'MyISAM') === 0);
                $message = $myisam
                    ? 'This database already contains MyISAM tables, possibly from a previous setup attempt. Twin POS requires InnoDB. Use a new empty database or restore this database to an empty state if it contains only disposable setup data.'
                    : 'This database already contains tables or views. Use an empty database. The installer preserves existing records.';
                throw ValidationException::withMessages(['db_database' => $message]);
            }

            if ($mysql) {
                $stage = 'InnoDB check';
                $engines = $connection->select('SHOW ENGINES');
                $supported = collect($engines)->contains(fn ($engine) => strcasecmp($engine->Engine, 'InnoDB') === 0
                    && in_array(strtoupper($engine->Support), ['YES', 'DEFAULT'], true));
                if (! $supported) {
                    throw ValidationException::withMessages(['db_database' => 'This database server does not offer InnoDB. Ask your hosting provider to enable InnoDB or use a server that supports it. Setup has not created any tables.']);
                }
            }

            $stage = 'Table migrations';
            if (Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Migration failed.');
            }

            $stage = 'Starter data';
            $connection->transaction(function () use ($data) {
                $shopCategories = config('installer.shops')[$data['shop_type']]['categories'];
                app(DatabaseSeeder::class)->run(
                    seedTestUser: false,
                    productCategories: ['General', ...$shopCategories],
                );
                $admin = User::create([
                    'name' => $data['admin_name'],
                    'username' => $data['admin_username'],
                    'email' => $data['admin_email'],
                    'password' => $data['password'],
                    'role_id' => Role::where('name', 'Administrator')->firstOrFail()->id,
                ]);
                Setting::where('key', 'business_name')->update(['value' => json_encode($data['shop_name'], JSON_THROW_ON_ERROR)]);
                if (! empty($data['sample_data'])) {
                    app(SampleProductSeeder::class)->seedFor($admin, $data['shop_type'], $data['modules'] ?? []);
                }
            });
        } catch (ValidationException $error) {
            if (! in_array($stage, ['Table migrations', 'Starter data'], true)) {
                throw $error;
            }
            throw $this->databaseFailure($stage, $connection, $error);
        } catch (Throwable $error) {
            throw $this->databaseFailure($stage, $connection, $error);
        } finally {
            DB::purge(self::CONNECTION);
            config(['database.default' => $original, 'database.connections.'.self::CONNECTION => null]);
        }
    }

    private function databaseFailure(string $stage, ?Connection $connection, Throwable $error): ValidationException
    {
        $state = null;
        $code = null;
        for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
            if (! $cause instanceof PDOException) {
                continue;
            }
            $candidate = (string) ($cause->errorInfo[0] ?? $cause->getCode());
            $state ??= preg_match('/\A[A-Z0-9]{5}\z/', $candidate) ? $candidate : null;
            $native = $cause->errorInfo[1] ?? null;
            $code ??= is_numeric($native) ? (int) $native : null;
        }

        $migration = $stage === 'Table migrations' && $connection ? $this->pendingMigration($connection) : null;
        $detail = match ($code) {
            1044, 1142, 1143, 1227 => 'The database user lacks a required permission. In cPanel, add the user to this database and grant all privileges for this database.',
            1045, 1698 => 'The database server rejected the login. Check the full database username and password, and confirm the host matches the user grant.',
            1049 => 'The database does not exist. Create it in your hosting panel and enter its full name, including the account prefix.',
            2002, 2003, 2005 => 'Could not reach the database server. Confirm the host and port with your hosting provider.',
            2006, 2013 => 'The connection to the database server was lost. Check server availability and retry.',
            1005 => 'The server could not create a required table. Ask your hosting provider to check database privileges and InnoDB support.',
            1215, 1822, 3780 => 'A foreign key could not be created. Twin POS requires InnoDB tables and compatible foreign key columns.',
            1091 => 'A required column, index or foreign key was missing. Use an empty database with InnoDB enabled.',
            1050, 1060, 1061 => 'A table, column or index already exists. The database may contain part of an earlier installation.',
            1286, 3161 => 'The server cannot create InnoDB tables. Ask your host to enable InnoDB.',
            1071, 1214, 1709 => 'The server rejected a required database index. Ask your host to check InnoDB and FULLTEXT support.',
            default => $state === '28000'
                ? 'The database server rejected the login. Check the database username and password.'
                : 'Check the application log or contact your hosting provider with the setup step and error code below.',
        };

        logger()->error('Twin installer database setup failed.', [
            'stage' => $stage, 'migration' => $migration, 'sqlstate' => $state,
            'database_code' => $code, 'exception_type' => $error::class,
        ]);

        $step = $stage.($migration ? ' ('.$migration.')' : '');
        $identifier = implode(' / ', array_filter([$state, $code], fn ($value) => $value !== null));
        $retry = in_array($stage, ['Table migrations', 'Starter data'], true)
            ? ' Setup may have created tables before it stopped. Retry with an empty database after fixing the error.'
            : '';

        return ValidationException::withMessages([
            'db_database' => $step.' failed'.($identifier !== '' ? ' ['.$identifier.']' : '').'. '.$detail.$retry,
        ]);
    }

    private function pendingMigration(Connection $connection): ?string
    {
        try {
            $table = config('database.migrations.table', 'migrations');
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return null;
            }

            $completed = $connection->table($table)->pluck('migration')->all();
            $files = app('migrator')->getMigrationFiles(database_path('migrations'));

            return array_key_first(array_diff_key($files, array_flip($completed)));
        } catch (Throwable) {
            return null;
        }
    }

    protected function databaseConfig(#[\SensitiveParameter] array $data): array
    {
        return array_replace(config('database.connections.mysql'), [
            'url' => null,
            'host' => $data['db_host'],
            'port' => $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'] ?? '',
            'unix_socket' => '',
        ]);
    }

    private function moduleValues(array $data): array
    {
        $values = [];
        foreach (array_keys(config('installer.modules')) as $module) {
            $values[$module] = in_array($module, $data['modules'] ?? [], true);
        }

        return $values + ['TWIN_SHOP_TYPE' => $data['shop_type']];
    }

    private function environment(string $path, #[\SensitiveParameter] array $values): string
    {
        $content = file_get_contents($path);
        $existing = Dotenv::parse($content);
        $expected = [];
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $expected[$key] = $encoded = $value ? 'true' : 'false';
            } else {
                $expected[$key] = preg_match('/\A(?:\(?(?:true|false|empty|null)\)?|(["\']).*\1)\z/i', $value)
                    ? '"'.$value.'"'
                    : $value;
                $encoded = '"'.strtr($expected[$key], [
                    '\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\r" => '\\r', "\n" => '\\n',
                ]).'"';
            }
            $line = $key.'='.$encoded;
            if (array_key_exists($key, $existing)) {
                $content = preg_replace_callback('/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=.*$/m', fn () => $line, $content);
            } else {
                $content = rtrim($content)."\n".$line."\n";
            }
        }

        $parsed = Dotenv::parse($content);
        foreach ($expected as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new RuntimeException('Could not verify generated configuration.');
            }
        }

        return $content;
    }

    private function clearConfigurationCache(): void
    {
        if (app()->configurationIsCached() && Artisan::call('config:clear') !== 0) {
            throw new RuntimeException('Could not clear cached configuration.');
        }
    }

    private function newKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    private function stateFile(string $name): string
    {
        return config('installer.state_path').'/twininstall-'.$name;
    }

    private function synchronized(callable $callback): mixed
    {
        $directory = config('installer.state_path');
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Setup requires writable application storage.');
        }
        $file = @fopen($this->stateFile('lock'), 'c');
        if (! $file) {
            throw new RuntimeException('Setup requires writable application storage.');
        }
        try {
            if (! flock($file, LOCK_EX)) {
                throw new RuntimeException('Could not lock installer.');
            }

            return $callback();
        } finally {
            fclose($file);
        }
    }

    private function writeAtomically(string $path, #[\SensitiveParameter] string $contents, ?callable $beforePublish = null): void
    {
        $temp = @tempnam(dirname($path), '.twininstall-');
        if (! $temp || realpath(dirname($temp)) !== realpath(dirname($path))) {
            if ($temp) {
                @unlink($temp);
            }
            throw new RuntimeException('The application directory is not writable.');
        }
        try {
            if (! chmod($temp, is_file($path) ? fileperms($path) & 0777 : 0600)
                || file_put_contents($temp, $contents) !== strlen($contents)) {
                throw new RuntimeException('Could not write installer configuration.');
            }
            $beforePublish?->__invoke();
            if (! rename($temp, $path)) {
                throw new RuntimeException('Could not publish installer configuration.');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
