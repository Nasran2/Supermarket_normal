<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MigrationController extends Controller
{
    /**
     * Display the migration dashboard with database status and migration list.
     */
    public function index(Request $request)
    {
        $authorized = $this->isAuthorized($request);
        $configuredPin = $this->getConfiguredPin();
        $hasPinConfigured = filled($configuredPin);
        $migrationData = $this->getMigrationData();

        return view('migration.index', array_merge($migrationData, [
            'authorized' => $authorized,
            'hasPinConfigured' => $hasPinConfigured,
            'requiresUnlock' => $hasPinConfigured && ! $authorized,
            'user' => $request->user(),
        ]));
    }

    /**
     * Run all pending migrations.
     */
    public function run(Request $request)
    {
        if (! $this->isAuthorized($request)) {
            return $this->unauthorizedResponse($request);
        }

        try {
            // Set time limit for large migrations
            @set_time_limit(300);

            $exitCode = Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
            $output = trim(Artisan::output());

            // Clear cache after running migrations
            try {
                Artisan::call('config:clear');
                Artisan::call('route:clear');
                Artisan::call('view:clear');
            } catch (\Throwable) {
                // Non-fatal if cache clear fails
            }

            if ($exitCode === 0) {
                $message = 'Migrations executed successfully!';
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'output' => $output,
                    ]);
                }

                return redirect()->route('migration.index')
                    ->with('success', $message)
                    ->with('console_output', $output);
            }

            throw new \RuntimeException($output ?: 'Migration command exited with code ' . $exitCode);
        } catch (\Throwable $e) {
            logger()->error('Web migration error: ' . $e->getMessage(), ['exception' => $e]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'output' => Artisan::output(),
                ], 500);
            }

            return redirect()->route('migration.index')
                ->with('error', 'Migration failed: ' . $e->getMessage())
                ->with('console_output', Artisan::output() ?: $e->getMessage());
        }
    }

    /**
     * Clear application cache (optimize:clear).
     */
    public function clearCache(Request $request)
    {
        if (! $this->isAuthorized($request)) {
            return $this->unauthorizedResponse($request);
        }

        try {
            Artisan::call('optimize:clear');
            $output = trim(Artisan::output());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Application cache cleared.',
                    'output' => $output,
                ]);
            }

            return redirect()->route('migration.index')
                ->with('success', 'Application cache cleared successfully!')
                ->with('console_output', $output);
        } catch (\Throwable $e) {
            return redirect()->route('migration.index')
                ->with('error', 'Failed to clear cache: ' . $e->getMessage());
        }
    }

    /**
     * Create storage symlink (storage:link).
     */
    public function storageLink(Request $request)
    {
        if (! $this->isAuthorized($request)) {
            return $this->unauthorizedResponse($request);
        }

        try {
            Artisan::call('storage:link');
            $output = trim(Artisan::output());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Storage link command completed.',
                    'output' => $output,
                ]);
            }

            return redirect()->route('migration.index')
                ->with('success', 'Storage link command completed successfully!')
                ->with('console_output', $output);
        } catch (\Throwable $e) {
            return redirect()->route('migration.index')
                ->with('error', 'Storage link failed: ' . $e->getMessage());
        }
    }

    /**
     * Unlock the migration page with a PIN or Administrator credentials.
     */
    public function unlock(Request $request)
    {
        $configuredPin = $this->getConfiguredPin();
        $pin = $request->input('pin');

        if (filled($configuredPin) && filled($pin) && hash_equals((string) $configuredPin, (string) $pin)) {
            $request->session()->put('migration_unlocked', true);
            return redirect()->route('migration.index')->with('success', 'Access granted.');
        }

        // Allow administrator credentials fallback
        $login = $request->input('username');
        $password = $request->input('password');
        if (filled($login) && filled($password)) {
            $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            if (Auth::attempt([$field => $login, 'password' => $password])) {
                if ($request->user() && method_exists($request->user(), 'isAdministrator') && $request->user()->isAdministrator()) {
                    $request->session()->put('migration_unlocked', true);
                    return redirect()->route('migration.index')->with('success', 'Logged in as Administrator.');
                }
                Auth::logout();
            }
        }

        return redirect()->route('migration.index')->with('error', 'Incorrect PIN or administrator credentials.');
    }

    /**
     * Lock the migration page.
     */
    public function lock(Request $request)
    {
        $request->session()->forget('migration_unlocked');
        return redirect()->route('migration.index')->with('info', 'Migration page locked.');
    }

    /**
     * Check if the current request is authorized to view or run migrations.
     */
    protected function isAuthorized(Request $request): bool
    {
        // 1. Authenticated administrator user
        if ($request->user() && method_exists($request->user(), 'isAdministrator') && $request->user()->isAdministrator()) {
            return true;
        }

        // 2. Previously unlocked in session
        if ($request->session()->get('migration_unlocked') === true) {
            return true;
        }

        $configuredPin = $this->getConfiguredPin();

        // 3. Query string ?key= or ?pin= matches configured PIN
        $inputPin = $request->input('pin', $request->input('key', $request->query('key', $request->query('pin'))));
        if (filled($configuredPin) && filled($inputPin) && hash_equals((string) $configuredPin, (string) $inputPin)) {
            $request->session()->put('migration_unlocked', true);
            return true;
        }

        // 4. If no PIN is configured, allow access
        if (empty($configuredPin)) {
            return true;
        }

        return false;
    }

    /**
     * Get configured migration PIN from env or installer config.
     */
    protected function getConfiguredPin(): ?string
    {
        $pin = env('MIGRATION_PIN') ?: env('MIGRATION_KEY');
        if (filled($pin)) {
            return (string) $pin;
        }

        $installerPin = config('installer.pin');
        return filled($installerPin) ? (string) $installerPin : null;
    }

    /**
     * Gather migration data and database details.
     */
    protected function getMigrationData(): array
    {
        $dbConnected = false;
        $dbError = null;
        $defaultConn = config('database.default', 'mysql');
        $dbConfig = [
            'connection' => $defaultConn,
            'driver' => config("database.connections.{$defaultConn}.driver", 'unknown'),
            'database' => config("database.connections.{$defaultConn}.database", 'unknown'),
            'host' => config("database.connections.{$defaultConn}.host", '127.0.0.1'),
            'port' => config("database.connections.{$defaultConn}.port", '3306'),
        ];

        try {
            DB::connection()->getPdo();
            $dbConnected = true;
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $migrations = [];
        $ranCount = 0;
        $pendingCount = 0;

        if ($dbConnected) {
            try {
                $migrator = app('migrator');
                $paths = array_values(array_unique(array_merge([database_path('migrations')], $migrator->paths())));
                $files = $migrator->getMigrationFiles($paths);

                $ran = [];
                $batches = [];
                if ($migrator->repositoryExists()) {
                    $ran = $migrator->getRepository()->getRan();
                    $batches = $migrator->getRepository()->getMigrationBatches();
                }

                foreach ($files as $name => $path) {
                    $isRan = in_array($name, $ran, true);
                    if ($isRan) {
                        $ranCount++;
                    } else {
                        $pendingCount++;
                    }

                    $migrations[] = [
                        'name' => $name,
                        'path' => $path,
                        'ran' => $isRan,
                        'batch' => $batches[$name] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                $dbError = 'Could not read migrations: ' . $e->getMessage();
            }
        }

        return [
            'dbConnected' => $dbConnected,
            'dbError' => $dbError,
            'dbConfig' => $dbConfig,
            'migrations' => $migrations,
            'totalCount' => count($migrations),
            'ranCount' => $ranCount,
            'pendingCount' => $pendingCount,
        ];
    }

    /**
     * Standard unauthorized response.
     */
    protected function unauthorizedResponse(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access.'], 403);
        }

        return redirect()->route('migration.index')->with('error', 'Unauthorized access. Please enter your migration PIN or log in as Administrator.');
    }
}
