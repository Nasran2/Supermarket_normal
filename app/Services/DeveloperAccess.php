<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Hr;
use App\Support\Permissions;
use Illuminate\Support\Facades\Schema;

class DeveloperAccess
{
    private ?array $disabled = null;

    public static function modules(): array
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }
        $modules = [];
        $add = function (string $key, string $label, string $section, ?string $parent = null, ?string $requires = null) use (&$modules) {
            $modules[$key] = compact('label', 'section', 'parent', 'requires');
        };
        foreach (['dashboard' => 'Dashboard', 'pos' => 'Point of sale', 'sales' => 'Sales', 'purchases' => 'Purchases', 'register' => 'Daily register', 'repairs' => 'Phone repairs', 'sms' => 'SMS & reminders'] as $key => $label) {
            $add($key, $label, 'Workspace', null, ['register' => 'REGISTER_CASHIER', 'repairs' => 'PHONE_REP', 'sms' => 'SMS'][$key] ?? null);
        }
        foreach (['products' => 'Products', 'categories' => 'Categories', 'units' => 'Units', 'unit-presets' => 'Multiple units', 'suppliers' => 'Suppliers', 'customers' => 'Customers', 'expenses' => 'Expenses', 'cheques' => 'Cheques', 'barcode' => 'Barcode labels', 'expiry' => 'Expiry'] as $key => $label) {
            $add($key, $label, 'Inventory', $key === 'unit-presets' ? 'units' : null, ['units' => 'UNIT', 'unit-presets' => 'UNIT', 'cheques' => 'CHEQUE', 'barcode' => 'BARCODE', 'expiry' => 'EXPIRE'][$key] ?? null);
        }
        $add('stock-adjustments', 'Stock adjustments', 'Inventory', 'products');
        $add('products.low_stock', 'Low stock', 'Inventory', 'products');
        $add('expense-categories', 'Expense categories', 'Inventory', 'expenses');
        $add('sales_returns', 'Sales returns', 'Workspace', 'sales');
        $add('purchase_returns', 'Purchase returns', 'Workspace', 'purchases');
        $add('supplier_returns', 'Supplier returns', 'Inventory', 'suppliers');
        $add('returns', 'Shared return controls', 'Workspace');
        foreach (['reports' => 'Reports', 'users' => 'Users', 'roles' => 'Roles & permissions', 'settings' => 'Settings', 'documentation' => 'Documentation', 'hr' => 'Human resources'] as $key => $label) {
            $add($key, $label, 'Management', null, $key === 'hr' ? 'hr_module' : null);
        }
        foreach (ReportService::TITLES as $kind => $label) {
            $add('reports.'.$kind, $label, 'Management', 'reports');
        }
        foreach (['branding' => 'Appearance & branding', 'business' => 'Business', 'pos' => 'Point of sale settings', 'customer_tax' => 'Tax settings', 'receipt' => 'Receipt settings', 'stock' => 'Stock controls', 'system' => 'System', 'returns' => 'Return settings', 'sms' => 'SMS settings', 'barcode' => 'Barcode settings', 'cheques' => 'Cheque settings', 'expiry' => 'Expiry settings', 'repairs' => 'Repair settings'] as $key => $label) {
            $add('settings.'.$key, $label, 'Management', 'settings');
        }
        $add('payment-methods', 'Payment methods', 'Management', 'settings');
        $add('payment-rules', 'Payment rules', 'Management', 'settings');
        $add('migration', 'Database migrations & maintenance', 'Management', 'settings');
        foreach (['staff' => 'Staff', 'attendance' => 'Attendance', 'leave' => 'Leave requests', 'payroll' => 'Payroll', 'payments' => 'Payments & advances', 'ledger' => 'Staff ledgers', 'setup' => 'HR setup', 'reports' => 'HR reports'] as $key => $label) {
            $add('hr.'.$key, $label, 'Management', 'hr');
        }
        foreach (Hr::REPORTS as $kind => $label) {
            $add('hr.reports.'.$kind, $label, 'Management', 'hr.reports');
        }
        $add('repairs.catalog', 'Device catalog', 'Workspace', 'repairs');
        $add('repairs.reports', 'Repair reports', 'Workspace', 'repairs');
        $add('cheques.reports', 'Cheque reports', 'Inventory', 'cheques');
        foreach (['all' => 'All cheques', 'received' => 'Received cheques', 'issued' => 'Issued cheques', 'third-party' => 'Third-party cheques', 'due-soon' => 'Cheques due soon', 'returned' => 'Returned cheques'] as $key => $label) {
            $add('cheques.'.$key, $label, 'Inventory', 'cheques');
        }

        return $cached = $modules;
    }

    public static function permissionModule(string $permission): ?string
    {
        $special = [
            'settings.business' => 'settings',
            'purchase.expiry_edit' => 'purchases',
            'sales.return' => 'sales_returns',
            'dashboard.register' => 'dashboard',
            'sms.settings' => 'settings.sms',
            'barcode.manage_settings' => 'settings.barcode',
            'cheques.manage_settings' => 'settings.cheques',
            'cheques.view_reports' => 'cheques.reports', 'cheques.export' => 'cheques.reports',
            'expiry.manage_settings' => 'settings.expiry',
            'repairs.settings' => 'repairs',
        ];
        if (isset($special[$permission])) {
            return $special[$permission];
        }
        $modules = self::modules();
        $key = $permission;
        while ($key !== '') {
            if (isset($modules[$key])) {
                return $key;
            }
            $dot = strrpos($key, '.');
            $key = $dot === false ? '' : substr($key, 0, $dot);
        }

        return null;
    }

    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::modules() as $key => $module) {
            $catalog['module:'.$key] = ['label' => $module['label'], 'module' => $key, 'type' => 'module'];
        }
        foreach (Permissions::all() as $key => $permission) {
            $catalog['permission:'.$key] = ['label' => $permission['label'], 'description' => $permission['description'], 'module' => self::permissionModule($key), 'type' => 'permission'];
        }
        foreach (['run' => 'Run database migrations', 'cache' => 'Clear application caches', 'storage-link' => 'Create storage link'] as $action => $label) {
            $catalog['permission:migration.'.$action] = ['label' => $label, 'description' => '', 'module' => 'migration', 'type' => 'permission'];
        }
        foreach (['expiry_alerts' => 'Expiry alerts', 'cheque_alerts' => 'Cheque alerts', 'repair_stats' => 'Repair overview'] as $card => $label) {
            $catalog['permission:dashboard.'.$card] = ['label' => $label, 'description' => 'Show this dashboard card when its module is available.', 'module' => 'dashboard', 'type' => 'permission'];
        }

        return $catalog;
    }

    public function reset(): void
    {
        $this->disabled = null;
    }

    public function disabled(): array
    {
        if ($this->disabled === null) {
            $values = Schema::hasTable('settings') ? json_decode(Setting::where('key', 'developer_access')->value('value') ?? '[]', true) : [];
            $this->disabled = array_fill_keys(is_array($values) ? array_filter($values, 'is_string') : [], true);
        }

        return $this->disabled;
    }

    public function selected(string $key): bool
    {
        return ! isset($this->disabled()[$key]);
    }

    public static function dependencies(): array
    {
        return [
            'reports.register' => 'register', 'reports.cash' => 'register',
            'settings.sms' => 'sms', 'settings.barcode' => 'barcode',
            'settings.cheques' => 'cheques', 'settings.expiry' => 'expiry', 'settings.repairs' => 'repairs',
            'repairs.reports' => 'reports', 'cheques.reports' => 'reports', 'hr.reports' => 'reports',
        ];
    }

    public function moduleAllowed(string $key): bool
    {
        $modules = self::modules();
        $dependencies = self::dependencies();
        do {
            if (! $this->selected('module:'.$key)) {
                return false;
            }
            if (isset($dependencies[$key]) && ! $this->moduleAllowed($dependencies[$key])) {
                return false;
            }
            $key = $modules[$key]['parent'] ?? null;
        } while ($key !== null);

        return true;
    }

    public function permissionAllowed(string $permission): bool
    {
        $module = self::permissionModule($permission);

        return $this->selected('permission:'.$permission) && ($module === null || $this->moduleAllowed($module));
    }

    public function save(array $enabled): void
    {
        $disabled = array_values(array_diff(array_keys(self::catalog()), $enabled));
        Setting::updateOrCreate(['key' => 'developer_access'], ['group' => 'developer', 'value' => json_encode($disabled, JSON_THROW_ON_ERROR)]);
        $this->reset();
    }

    public static function contact(): array
    {
        $name = trim((string) config('app.developer.name'));
        $phone = trim((string) config('app.developer.phone'));
        $website = trim((string) config('app.developer.web'));
        $url = preg_match('~^https?://~i', $website) ? $website : 'https://'.$website;
        $url = filter_var($url, FILTER_VALIDATE_URL) ? $url : null;

        return compact('name', 'phone', 'website', 'url');
    }
}
