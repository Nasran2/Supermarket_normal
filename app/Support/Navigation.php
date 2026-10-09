<?php

namespace App\Support;

use App\Models\User;
use App\Services\ReportService;

final class Navigation
{
    public static function home(User $user): string
    {
        foreach (['dashboard.view' => 'dashboard', 'pos.access' => 'pos.index', 'sales.view' => 'sales.index', 'register.view' => 'register.index', 'purchases.view' => 'purchases.index', 'settings.view' => 'settings.index'] as $permission => $route) {
            if ($user->hasPermission($permission)) {
                return route($route);
            }
        }
        foreach (collect(Resources::all())->map(fn ($def, $resource) => Resources::permission($resource, 'view'))->all() as $resource => $permission) {
            if ($user->hasPermission($permission)) {
                return route('manage.index', $resource);
            }
        }
        if (collect(array_keys(ReportService::TITLES))->contains(fn ($kind) => $user->hasPermission(ReportService::permission($kind)))) {
            return route('reports.index');
        }

        if (Hr::enabled()) {
            foreach (['hr.view' => 'hr.index', 'hr.staff.view' => 'hr.staff.index', 'hr.attendance.view' => 'hr.attendance', 'hr.leave.view' => 'hr.leave', 'hr.payroll.view' => 'hr.payroll.index', 'hr.payments.view' => 'hr.payments', 'hr.setup.view' => 'hr.setup'] as $permission => $route) {
                if ($user->hasPermission($permission)) {
                    return route($route);
                }
            }
            foreach (Hr::REPORTS as $kind => $label) {
                if ($user->hasPermission('hr.reports.'.$kind.'.view')) {
                    return route('hr.reports.show', $kind);
                }
            }
        }

        return route('profile');
    }
}
