<?php

use App\Http\Middleware\BusinessContext;
use App\Http\Middleware\DemoMode;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['permission' => PermissionMiddleware::class]);
        $middleware->appendToGroup('web', [DemoMode::class, BusinessContext::class]);
        // Acquire the demo lock and restore overdue data before binding records.
        $middleware->prependToPriorityList(SubstituteBindings::class, DemoMode::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
