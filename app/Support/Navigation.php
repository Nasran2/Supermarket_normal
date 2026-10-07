<?php

namespace App\Support;

use App\Models\User;

final class Navigation
{
    public static function home(User $user): string
    {
        foreach (['dashboard.view' => 'dashboard', 'pos.access' => 'pos.index', 'sales.view' => 'sales.index', 'register.view' => 'register.index', 'purchases.view' => 'purchases.index', 'settings.view' => 'settings.index'] as $permission => $route) {
            if ($user->hasPermission($permission)) {
                return route($route);
            }
        }
        foreach (['products' => 'products.view', 'expenses' => 'expenses.view', 'users' => 'users.view', 'roles' => 'roles.view', 'units' => 'units.view'] as $resource => $permission) {
            if ($user->hasPermission($permission)) {
                return route('manage.index', $resource);
            }
        }
        if ($user->role?->permissions->contains(fn ($p) => str_starts_with($p->name, 'reports.'))) {
            return route('reports.index');
        }

        return route('profile');
    }
}
