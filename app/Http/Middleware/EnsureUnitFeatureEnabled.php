<?php

namespace App\Http\Middleware;

use App\Support\Units;
use Closure;
use Illuminate\Http\Request;

class EnsureUnitFeatureEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Units::enabled(), 404);

        return $next($request);
    }
}
