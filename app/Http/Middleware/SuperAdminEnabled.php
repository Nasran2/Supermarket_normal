<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SuperAdminEnabled
 * Blocks access to the super admin panel when SUPER_ADMIN=false in .env.
 */
class SuperAdminEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('features.super_admin'), 404);
        $guard = Auth::guard('superadmin');
        if ($guard->check() && ! $guard->user()->active) {
            $guard->logout();
            $request->session()->regenerate();

            return $request->expectsJson()
                ? response()->json(['message' => 'Your account is inactive.'], 401)
                : redirect()->route('superadmin.login');
        }

        return $next($request);
    }
}

