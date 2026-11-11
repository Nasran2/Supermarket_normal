<?php

namespace App\Http\Middleware;

use App\Services\Installer;
use Closure;
use Illuminate\Http\Request;

class PrepareInstallerSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('twininstall')) {
            return $next($request);
        }

        $installer = app(Installer::class);
        config(['cache.limiter' => 'file']);
        if (! $installer->locked() || ! config('app.key')) {
            config([
                'app.key' => $installer->bootstrapKey(),
                'session.driver' => 'file',
                'session.cookie' => 'twin_install_session',
                'session.secure' => $request->isSecure(),
                'session.domain' => null,
                'session.path' => '/',
                'cache.default' => 'file',
            ]);
        }

        return $next($request);
    }
}
