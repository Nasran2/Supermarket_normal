<?php

use App\Http\Middleware\BusinessContext;
use App\Http\Middleware\DemoMode;
use App\Http\Middleware\EnsureBarcodeFeatureEnabled;
use App\Http\Middleware\EnsureChequeFeatureEnabled;
use App\Http\Middleware\EnsureSmsEnabled;
use App\Http\Middleware\EnsureUnitFeatureEnabled;
use App\Http\Middleware\GuardUnitResources;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SuperAdminEnabled;
use App\Http\Middleware\SystemActive;
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
        $middleware->append(SecurityHeaders::class);
        $middleware->trimStrings(except: ['device_unlock']);
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\Http\Middleware\RepairEnabled::class);
        $middleware->alias(['sms-feature' => EnsureSmsEnabled::class, 'permission' => PermissionMiddleware::class, 'barcode-feature' => EnsureBarcodeFeatureEnabled::class, 'cheque-feature' => EnsureChequeFeatureEnabled::class, 'unit-feature' => EnsureUnitFeatureEnabled::class, 'unit-resources' => GuardUnitResources::class, 'superadmin.enabled' => SuperAdminEnabled::class, 'system.active' => SystemActive::class]);
        $middleware->appendToGroup('web', [DemoMode::class, BusinessContext::class, SystemActive::class]);
        // Acquire the demo lock and restore overdue data before binding records.
        $middleware->prependToPriorityList(SubstituteBindings::class, DemoMode::class);

        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => $request->is('superadmin*') ? route('superadmin.login') : route('login'));
        $middleware->redirectUsersTo(fn (\Illuminate\Http\Request $request) => \Illuminate\Support\Facades\Auth::guard('superadmin')->check() ? route('superadmin.dashboard') : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['device_unlock']);
    })->create();
