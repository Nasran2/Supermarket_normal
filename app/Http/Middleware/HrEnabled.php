<?php

namespace App\Http\Middleware;

use App\Support\Hr;
use Closure;

class HrEnabled
{
    public function handle($request, Closure $next)
    {
        abort_unless(Hr::enabled(), 404);

        return $next($request);
    }
}
