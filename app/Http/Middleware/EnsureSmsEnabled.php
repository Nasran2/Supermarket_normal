<?php

namespace App\Http\Middleware;

use Closure;

class EnsureSmsEnabled
{
    public function handle($request, Closure $next)
    {
        abort_unless(config('sms.enabled'), 404);

        return $next($request);
    }
}
