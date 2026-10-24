<?php

namespace App\Http\Middleware;

use App\Exceptions\DemoBaselineMismatchException;
use App\Services\DemoDataService;
use App\Support\Demo;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class DemoMode
{
    public function handle(Request $request, Closure $next)
    {
        View::share(['demoMode' => Demo::enabled(), 'demoReadOnly' => false, 'demoCycle' => null, 'demoNextReset' => null, 'demoResetBlocked' => false]);
        if (! Demo::enabled()) {
            return $next($request);
        }
        if (! $request->isMethodSafe() && Demo::protectedPage($request)) {
            abort(403, 'Demo mode: business details, settings and login accounts cannot be changed.');
        }
        try {
            return app(DemoDataService::class)->synchronized(function () use ($request, $next) {
                try {
                    $state = app(DemoDataService::class)->refresh();
                } catch (DemoBaselineMismatchException $exception) {
                    // Keep serving the demo without deleting data from an incompatible snapshot.
                    $state = $exception->state;
                    View::share('demoResetBlocked', true);
                    Log::warning('Demo automatic reset paused; the installation owner must review the baseline after schema changes.', ['reason' => $exception->getMessage(), 'cycle' => $state['cycle']]);
                }
                View::share(['demoReadOnly' => Demo::readOnlyForm($request), 'demoCycle' => $state['cycle'], 'demoNextReset' => $state['next_reset_at']]);
                if ($state['reset'] && ! $request->isMethodSafe() && ! $request->routeIs('logout')) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'The demo data has just reset. Reload the page and try again.'], 409);
                    }

                    return redirect()->back()->withErrors(['demo' => 'The demo data has just reset. Reload the page and try again.']);
                }

                return $next($request);
            });
        } catch (LockTimeoutException) {
            abort(503, 'The demo is busy or resetting. Please try again shortly.');
        }
    }
}
