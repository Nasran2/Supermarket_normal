<?php

namespace App\Services;

use Illuminate\Http\Request;

class DeveloperRouteAccess
{
    public static function modules(string $name, array $parameters = []): array
    {
        if (str_starts_with($name, 'manage.')) {
            if (($parameters['resource'] ?? null) === 'products' && ! empty($parameters['low_stock'])) {
                return ['products.low_stock'];
            }

            return isset($parameters['resource']) ? [$parameters['resource']] : (str_starts_with($name, 'manage.customers.') ? ['customers'] : ['suppliers']);
        }
        if (str_starts_with($name, 'reports.')) {
            return isset($parameters['report']) ? ['reports', 'reports.'.$parameters['report']] : ['reports'];
        }
        if (str_starts_with($name, 'settings.')) {
            $page = $parameters['group'] ?? explode('.', $name)[1];
            $modules = ['settings'];
            if ($page !== 'index') {
                $modules[] = 'settings.'.$page;
            }

            return $modules;
        }
        if (str_starts_with($name, 'returns.')) {
            return str_starts_with($name, 'returns.suppliers') || $name === 'returns.supplier-credit'
                ? ['supplier_returns'] : [($parameters['kind'] ?? 'sales').'_returns'];
        }
        if (str_starts_with($name, 'sales.returns.')) {
            return ['sales_returns'];
        }
        if (str_starts_with($name, 'hr.')) {
            $parts = explode('.', $name);
            $part = $parts[1];
            if ($part === 'reports') {
                return ['hr.reports', 'reports', ...isset($parameters['kind']) ? ['hr.reports.'.$parameters['kind']] : []];
            }

            return ['hr', ...in_array($part, ['staff', 'attendance', 'leave', 'payroll', 'payments', 'setup']) ? ['hr.'.$part] : []];
        }
        if (str_starts_with($name, 'repairs.')) {
            if (str_starts_with($name, 'repairs.settings')) {
                return ['repairs', 'settings.repairs'];
            }
            if (str_starts_with($name, 'repairs.catalog')) {
                return ['repairs.catalog'];
            }

            return str_starts_with($name, 'repairs.reports') ? ['repairs.reports', 'reports'] : ['repairs'];
        }
        if (in_array($name, ['cheques.report', 'cheques.export'])) {
            return ['cheques.reports', 'reports'];
        }
        if (in_array($name, ['cheques.index', 'cheques.tab'])) {
            return ['cheques.'.($parameters['tab'] ?? 'all')];
        }
        if (str_starts_with($name, 'expiry.')) {
            return ['expiry', ...in_array($name, ['expiry.index', 'expiry.pdf', 'expiry.export']) ? ['reports'] : []];
        }
        if (str_starts_with($name, 'stock-prices.')) {
            return ['products'];
        }
        if (str_starts_with($name, 'adjustments.') || str_starts_with($name, 'stock.')) {
            return ['stock-adjustments'];
        }
        if ($name === 'receipts.public') {
            return ['sales', 'sms'];
        }
        $prefix = explode('.', $name)[0];

        return isset(DeveloperAccess::modules()[$prefix]) ? [$prefix] : [];
    }

    public static function actionPermissions(string $name, array $parameters = [], string $method = 'GET'): array
    {
        $crud = ['index' => 'view', 'show' => 'view', 'create' => 'create', 'store' => 'create', 'edit' => 'edit', 'update' => 'edit', 'destroy' => 'delete'];
        if (str_starts_with($name, 'manage.') && isset($parameters['resource'])) {
            return [[$parameters['resource'].'.'.($crud[explode('.', $name)[1]] ?? 'view')]];
        }
        if (str_starts_with($name, 'reports.') && isset($parameters['report'])) {
            return [['reports.'.$parameters['report']], ...$name !== 'reports.show' ? [['reports.'.$parameters['report'].($name === 'reports.pdf' ? '.pdf' : '.export')]] : []];
        }
        if (in_array($name, ['settings.edit', 'settings.update'])) {
            $group = $parameters['group'] ?? '';

            return [[match ($group) {
                'customer_tax', 'branding' => 'settings.business', 'expiry' => 'expiry.manage_settings', default => 'settings.'.$group,
            }]];
        }
        if (str_starts_with($name, 'returns.') && isset($parameters['kind'])) {
            $prefix = $parameters['kind'].'_returns.';
            $action = match (explode('.', $name)[1]) {
                'create', 'products', 'no-receipt', 'draft', 'quote', 'store' => 'create',
                'cancel' => 'cancel', default => 'view',
            };

            return [[$prefix.$action]];
        }
        if (str_starts_with($name, 'hr.reports.') && isset($parameters['kind'])) {
            $prefix = 'hr.reports.'.$parameters['kind'];

            return [[$prefix.'.view'], ...isset($parameters['format']) ? [[$prefix.($parameters['format'] === 'pdf' ? '.pdf' : '.export')]] : []];
        }
        if ($name === 'repairs.reports' && isset($parameters['export'])) {
            return [['repairs.reports.export']];
        }
        if ($name === 'cheques.action') {
            return [['cheques.'.($parameters['action'] ?? '')]];
        }
        if ($name === 'repairs.payment') {
            return [[($parameters['action'] ?? '') === 'refund' ? 'repairs.correct_payment' : 'repairs.pay']];
        }
        $permissions = [
            'pos.customers.store' => 'customers.create', 'pos.quote' => 'sales.create', 'pos.complete' => 'sales.create',
            'sales.edit.quote' => 'sales.edit', 'sales.revise' => 'sales.edit', 'sales.update' => 'sales.edit',
            'sales.destroy' => 'sales.delete', 'sales.void' => 'sales.void',
            'sales.returns.store' => 'sales.return', 'sales.collections.store' => 'sales.collect_payment',
            'sales.tax.context' => 'sales.receipt', 'sales.tax.preview' => 'sales.receipt', 'sales.tax.store' => 'sales.receipt', 'sales.tax.download' => 'sales.receipt',
            'purchases.store' => 'purchases.create', 'purchases.update' => 'purchases.edit',
            'purchases.destroy' => 'purchases.delete', 'purchases.void' => 'purchases.void',
            'purchases.payments.store' => 'purchases.pay', 'purchases.refunds.store' => 'purchases.refund', 'purchases.balance' => 'purchases.set_balance',
            'register.open' => 'register.open', 'register.close' => 'register.close', 'register.movement' => 'register.movement',
            'cheques.store' => 'cheques.create', 'cheques.return' => 'cheques.return', 'cheques.resolve-return' => 'cheques.resolve_return',
            'barcode.assign' => 'barcode.edit_product_barcode', 'barcode.preview' => 'barcode.print', 'barcode.jobs.store' => 'barcode.print',
            'stock.update' => 'stock-adjustments.create', 'expiry.layer' => 'purchase.expiry_edit', 'expiry.writeoff' => 'expiry.writeoff',
            'expiry.index' => 'expiry.view', 'expiry.pdf' => 'expiry.view', 'expiry.export' => 'expiry.view',
            'manage.customers.payments.store' => 'customers.collect_payment',
            'manage.customers.ledger' => 'customers.ledger', 'manage.suppliers.ledger' => 'suppliers.ledger',
            'returns.suppliers' => 'supplier_returns.view', 'returns.suppliers.update' => 'supplier_returns.manage', 'returns.supplier-credit' => 'purchase_returns.apply_supplier_credit',
            'repairs.update' => 'repairs.edit', 'repairs.consent' => 'repairs.edit', 'repairs.unlock' => 'repairs.unlock',
            'repairs.parts.add' => 'repairs.parts', 'repairs.parts.change' => 'repairs.parts', 'repairs.payment.correct' => 'repairs.correct_payment',
            'repairs.cost' => 'repairs.edit', 'repairs.attach' => 'repairs.edit',
            'migration.run' => 'migration.run', 'migration.cache' => 'migration.cache', 'migration.storage-link' => 'migration.storage-link',
        ];
        $result = isset($permissions[$name]) ? [[$permissions[$name]]] : [];
        if (in_array($name, ['manage.customers.ledger', 'manage.suppliers.ledger']) && isset($parameters['format'])) {
            $result[] = [str_contains($name, 'customers') ? 'customers.export' : 'suppliers.export'];
        }

        return $result;
    }

    public static function navigationAllowed(string $name, array|string $parameters = []): bool
    {
        if (is_string($parameters)) {
            $key = match (true) {
                str_starts_with($name, 'manage.') => 'resource',
                str_starts_with($name, 'reports.') => 'report',
                str_starts_with($name, 'settings.') => 'group',
                $name === 'cheques.tab' => 'tab',
                default => 'kind',
            };
            $parameters = [$key => $parameters];
        }
        $access = app(DeveloperAccess::class);

        return collect(self::modules($name, $parameters))->every(fn ($module) => $access->moduleAllowed($module));
    }

    public static function blocked(Request $request): bool
    {
        $route = $request->route();
        if (! $route) {
            return false;
        }
        $name = $route->getName() ?? '';
        $parameters = $route->parameters() + $request->only('format', 'export', 'low_stock');
        if (! self::navigationAllowed($name, $parameters)) {
            return true;
        }
        $requirements = self::actionPermissions($name, $parameters, $request->method());
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $requirements[] = explode('|', substr($middleware, strlen('permission:')));
            }
        }
        $access = app(DeveloperAccess::class);

        return collect($requirements)->contains(fn ($alternatives) => ! collect($alternatives)->contains(fn ($permission) => $access->permissionAllowed($permission)));
    }
}
