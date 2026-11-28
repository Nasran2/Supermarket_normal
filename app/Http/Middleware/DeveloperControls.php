<?php

namespace App\Http\Middleware;

use App\Services\DeveloperAccess;
use App\Services\DeveloperRouteAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeveloperControls
{
    public function handle(Request $request, Closure $next): Response
    {
        app(DeveloperAccess::class)->reset();
        if (DeveloperRouteAccess::blocked($request)) {
            $message = 'Please contact the developer to get permission to use this feature.';
            $contact = DeveloperAccess::contact();

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'developer' => $contact], 403)
                : response()->view('developer.blocked', compact('message', 'contact'), 403);
        }

        return $next($request);
    }
}
