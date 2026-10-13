<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureChequeFeatureEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('features.cheque'), 404);

        return $next($request);
    }
}
