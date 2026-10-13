<?php

namespace App\Providers;

use App\Models\Cheque;
use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use App\Policies\ChequePolicy;
use App\Policies\TransactionReturnPolicy;
use App\Services\SettingsService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        Gate::policy(Cheque::class, ChequePolicy::class);
        Gate::policy(SaleReturn::class, TransactionReturnPolicy::class);
        Gate::policy(PurchaseReturn::class, TransactionReturnPolicy::class);
        Schema::defaultStringLength(191);
        Paginator::useTailwind();
        Paginator::defaultView('components.pagination');
        Gate::before(fn ($user, $ability) => $user->hasPermission($ability) ? true : null);
    }
}
