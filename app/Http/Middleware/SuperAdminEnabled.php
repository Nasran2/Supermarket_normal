<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SuperAdminEnabled
 * Blocks access to the super admin panel when SUPER_ADMIN=false in .env.
 */
class SuperAdminEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! filter_var(config('features.super_admin', env('SUPER_ADMIN', false)), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        return $next($request);
    }
}

