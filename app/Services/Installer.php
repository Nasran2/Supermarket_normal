<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleProductSeeder;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            try {
                $connection = DB::connection(self::CONNECTION);
                $schema = $connection->getSchemaBuilder();
                $database = $schema->getCurrentSchemaName();
                $occupied = $schema->getTables($database) || $schema->getViews($database);
            } catch (Throwable) {
                throw ValidationException::withMessages(['db_database' => 'Could not connect. Check the database name, host, port, username and password.']);
            }
            if ($occupied) {
                throw ValidationException::withMessages(['db_database' => 'Use a new, empty database. Setup never overwrites an existing database.']);
            }

            try {
                if (Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true, '--no-interaction' => true]) !== 0) {
                    throw new RuntimeException('Migration failed.');
                }
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
            } catch (Throwable $error) {
                logger()->error('Twin installer database setup failed.', ['exception_type' => $error::class, 'code' => $error->getCode()]);
                throw ValidationException::withMessages(['setup' => 'Database setup stopped. Check the database permissions and requirements. Use an empty database when retrying.']);
            }
        } finally {
            DB::purge(self::CONNECTION);
            config(['database.default' => $original, 'database.connections.'.self::CONNECTION => null]);
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
