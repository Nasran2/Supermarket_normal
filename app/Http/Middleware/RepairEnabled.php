<?php

namespace App\Http\Middleware;

use App\Support\Repairs;
use Closure;

class RepairEnabled
{
    public function handle($request, Closure $next)
    {
        abort_unless(Repairs::enabled(), 404);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
