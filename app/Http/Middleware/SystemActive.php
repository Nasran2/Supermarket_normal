<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SystemActive
 * When the Super Admin disables the system, normal users see a maintenance page.
 * Super Admin routes are always accessible.
 */
class SystemActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always allow super admin panel routes through
        if ($request->is('superadmin*')) {
            return $next($request);
        }

        // Check if system_settings table exists and read the flag
        try {
            $setting = DB::table('system_settings')->where('key', 'system_active')->value('value');
            $isActive = $setting === null || (bool) $setting;
        } catch (\Throwable $e) {
            // Table doesn't exist yet (pre-migration) – allow through
            $isActive = true;
        }

        if (! $isActive) {
            return response(view('superadmin.system-offline'), 503);
        }

        return $next($request);
    }
}

