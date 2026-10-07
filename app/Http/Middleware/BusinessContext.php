<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class BusinessContext
{
    public function handle($request, Closure $next)
    {
        if (Schema::hasTable('settings')) {
            $settings = app(SettingsService::class)->all();
            config(['app.timezone' => $settings['timezone'] ?? 'Asia/Colombo']);
            date_default_timezone_set(config('app.timezone'));
            view()->share('settings', $settings);
        }

        if ($request->user() && (! $request->user()->active || ! $request->user()->role)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Your account is inactive.'], 401) : redirect()->route('login');
        }

        return $next($request);
    }
}
