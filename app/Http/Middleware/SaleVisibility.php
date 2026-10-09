<?php

namespace App\Http\Middleware;

use App\Models\Sale;
use App\Support\SalesVisibility;
use Closure;

class SaleVisibility
{
    public function handle($request, Closure $next)
    {
        $sale = $request->route('sale');
        if (is_string($sale) && ctype_digit($sale)) {
            $sale = Sale::findOrFail((int) $sale);
        }
        if ($sale instanceof Sale) {
            abort_unless(SalesVisibility::canSee($sale, $request->user()), 403);
        }

        return $next($request);
    }
}
