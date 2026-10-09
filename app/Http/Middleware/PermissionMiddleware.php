<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        abort_unless(collect(explode('|', $permission))->contains(fn ($ability) => $request->user()?->hasPermission($ability)), 403, 'You do not have permission to access this screen.');

        return $next($request);
    }
}
