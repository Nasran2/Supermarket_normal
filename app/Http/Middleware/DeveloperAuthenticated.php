<?php

namespace App\Http\Middleware;

use App\Http\Controllers\DeveloperController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeveloperAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DeveloperController::authenticated($request)) {
            $request->session()->forget('developer_auth');

            return $request->expectsJson()
                ? response()->json(['message' => 'Please sign in with the developer account.'], 401)
                : redirect()->route('developer.login');
        }
        $request->session()->put('developer_auth.last_seen', now()->timestamp);

        return $next($request);
    }
}
