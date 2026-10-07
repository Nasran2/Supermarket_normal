<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        Paginator::useTailwind();
        Gate::before(fn ($user, $ability) => $user->hasPermission($ability) ? true : null);
    }
}
