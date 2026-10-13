<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureBarcodeFeatureEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('features.barcode'), 404);

        return $next($request);
    }
}
