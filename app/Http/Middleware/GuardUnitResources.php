<?php

namespace App\Http\Middleware;

use App\Support\Units;
use Closure;
use Illuminate\Http\Request;

class GuardUnitResources
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->route('resource'), ['units', 'unit-presets'], true)) {
            abort_unless(Units::enabled(), 404);
        }

        return $next($request);
    }
}
