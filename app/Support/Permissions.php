<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;

/** The permission catalog is shared by authorization, installation and the role editor. */
class Permissions
{
    public static function all(): array
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }
        $catalog = [];
        $add = function ($name, $group, $label, $description, $legacy = []) use (&$catalog) {
            $catalog[$name] = compact('group', 'label', 'description', 'legacy');
        };
        $oldModules = ['categories' => 'products', 'unit-presets' => 'units', 'suppliers' => 'purchases', 'customers' => 'sales', 'expense-categories' => 'expenses', 'payment-methods' => 'settings.payment_methods', 'payment-rules' => 'settings.payment_rules'];
        foreach (Resources::all() + ['sales' => ['title' => 'Sales'], 'purchases' => ['title' => 'Purchases']] as $module => $definition) {
            foreach (['view' => 'View records and details', 'create' => 'Add new records', 'edit' => 'Edit existing records', 'delete' => 'Delete records'] as $action => $description) {
                $old = $oldModules[$module] ?? null;
                $legacy = $old ? [str_starts_with($old, 'settings.') ? $old : $old.'.'.$action] : [];
                $add($module.'.'.$action, $definition['title'], ucfirst($action), (in_array($module, ['sales', 'purchases', 'expenses']) && $action === 'delete' ? 'Reverse the transaction and retain its audit history.' : $description.'.'), $legacy);
            }
        }
        $add('dashboard.view', 'Dashboard', 'Open dashboard', 'Allow access to the dashboard page. Select its cards separately.');
        foreach (['sales' => 'Sales total', 'profit' => 'Net profit', 'expenses' => 'Expenses total', 'transactions' => 'Transaction count', 'collections' => 'Collections by payment method', 'sales_overview' => 'Sales overview chart', 'register' => 'Register status & opening cash', 'recent_sales' => 'Recent sales', 'low_stock' => 'Low stock alerts', 'top_products' => 'Top product sales', 'recent_expenses' => 'Recent expenses'] as $card => $label) {
            $add('dashboard.'.$card, 'Dashboard', $label, 'Show this dashboard card.'.($card === 'profit' ? ' Also requires View product costs.' : ''), ['dashboard.view']);
        }
        foreach ([
            'pos.access' => ['Point of sale', 'Open POS', 'Search products and build a cart. Completing an order also requires Create sales.', []],
            'pos.discount' => ['Point of sale', 'Apply discounts', 'Apply item and invoice discounts within the configured limits.', ['sales.create', 'sales.edit']],
            'pos.override_price' => ['Point of sale', 'Override selling price', 'Change the unit price for an order.', ['sales.create', 'sales.edit']],
            'pos.split_payment' => ['Point of sale', 'Split payments', 'Use multiple payment methods on an order.', ['sales.create', 'sales.edit']],
            'pos.due_sale' => ['Point of sale', 'Complete a sale with a balance due', 'Record unpaid or partially paid sales for a named customer.', ['sales.create']],
            'sales.void' => ['Sales', 'Void sale', 'Reverse a completed sale while retaining its audit history.', []],
            'sales.return' => ['Sales', 'Return items', 'Return purchased quantities and record refunds.', ['sales.void']],
            'sales.collect_payment' => ['Sales', 'Collect invoice payment', 'Collect the outstanding balance of an invoice.', ['sales.edit']],
            'sales.receipt' => ['Sales', 'Print receipt', 'Open and print an authorized invoice receipt.', ['sales.view', 'sales.create']],
            'purchases.void' => ['Purchases', 'Void purchase', 'Reverse a purchase and its remaining stock.', ['purchases.delete']],
            'purchases.pay' => ['Purchases', 'Pay supplier balance', 'Record a payment against an outstanding purchase.', ['purchases.edit']],
            'purchases.refund' => ['Purchases', 'Record supplier refund', 'Record money refunded by a supplier.', ['purchases.delete']],
            'purchases.set_balance' => ['Purchases', 'Set previous payment balance', 'Record historical payments before payment tracking started.', ['purchases.edit']],
            'purchases.manage_prices' => ['Purchases', 'Manage delivery prices', 'Change cost and selling prices on existing purchase stock.', []],
            'purchases.manage_charges' => ['Purchases', 'Manage additional charges', 'Add shipping and other charges; expense them or allocate them to product costs.', ['purchases.create', 'purchases.edit']],
            'products.view_cost' => ['Products', 'View product costs', 'See stock costs, margins and cost-based reports.', []],
            'products.manage_prices' => ['Products', 'Manage stock prices & costs', 'Change default product prices and remaining stock selling prices.', []],
            'products.view_history' => ['Products', 'View stock movement history', 'See movements and their related invoice references.', ['products.view']],
            'customers.collect_payment' => ['Customers', 'Collect customer payment', 'Collect and allocate a customer account payment.', ['sales.edit']],
            'customers.ledger' => ['Customers', 'View customer ledger', 'Review the customer account ledger.', ['sales.view']],
            'customers.export' => ['Customers', 'Download customer ledger', 'Export the customer ledger as PDF or CSV.', ['sales.view']],
            'suppliers.ledger' => ['Suppliers', 'View supplier ledger', 'Review purchase and payment history.', ['purchases.view']],
            'suppliers.export' => ['Suppliers', 'Download supplier ledger', 'Export the filtered supplier ledger as PDF or CSV.', ['purchases.view']],
            'units.set_default' => ['Units', 'Set default unit', 'Choose the default unit for new products.', ['units.edit']],
            'register.view' => ['Daily register', 'View own register', 'Review your register and cash summary.', []],
            'register.view_all' => ['Daily register', 'View all registers', 'Review other team members’ registers.', ['reports.register']],
            'register.open' => ['Daily register', 'Open register', 'Start a shift and record opening cash.', []],
            'register.close' => ['Daily register', 'Close register', 'End a shift and record counted cash.', []],
            'register.movement' => ['Daily register', 'Add or remove cash', 'Record a manual cash movement in your register.', ['register.close']],
            'settings.view' => ['Settings', 'View settings overview', 'Open the settings overview.', []],
            'settings.business' => ['Settings', 'Business details', 'Update company name, address, contact details and branding.', []],
            'settings.pos' => ['Settings', 'POS controls', 'Configure checkout defaults and discount limits.', []],
            'settings.receipt' => ['Settings', 'Receipt settings', 'Configure receipt layout and printing.', []],
            'settings.stock' => ['Settings', 'Stock controls', 'Configure stock rules.', ['settings.pos']],
            'settings.system' => ['Settings', 'System settings', 'Configure system preferences.', ['settings.business']],
        ] as $name => [$group, $label, $description, $legacy]) {
            $add($name, $group, $label, $description, $legacy);
        }
        foreach (['view', 'create', 'edit', 'delete'] as $action) {
            $old = $action === 'view' ? 'products.view' : 'products.edit';
            $add('stock-adjustments.'.$action, 'Stock adjustments', ucfirst($action), match ($action) {
                'view' => 'View stock correction history and details.', 'create' => 'Add a stock correction.', 'edit' => 'Revise an existing stock correction.', 'delete' => 'Reverse a stock correction.',
            }, [$old]);
        }
        foreach (ReportService::TITLES as $report => $title) {
            $name = ReportService::permission($report);
            $legacyReport = match ($report) {
                'returns' => 'reports.sales', 'collections' => 'reports.payments', default => null
            };
            $add($name, 'Reports · '.$title, 'View report', 'View and filter the '.$title.' report.', $legacyReport ? [$legacyReport] : []);
            $add($name.'.pdf', 'Reports · '.$title, 'Download PDF', 'Download a formatted report with company letterhead.', $legacyReport ? [$legacyReport, $legacyReport.'.pdf'] : [$name]);
            if ($report !== 'profit') {
                $add($name.'.export', 'Reports · '.$title, 'Export CSV', 'Export all matching records to CSV.', $legacyReport ? [$legacyReport, $legacyReport.'.export'] : [$name]);
            }
        }

        return $cached = $catalog;
    }

    public static function info(string $name): array
    {
        return self::all()[$name] ?? ['group' => 'Other', 'label' => $name, 'description' => '', 'legacy' => []];
    }

    /** Copy existing access only when a permission is introduced, never after revocation. */
    public static function install(): void
    {
        DB::transaction(function () {
            foreach (self::all() as $name => $info) {
                $permission = Permission::firstOrCreate(['name' => $name]);
                if ($permission->wasRecentlyCreated && $info['legacy']) {
                    $roleIds = DB::table('permission_role')->whereIn('permission_id', Permission::whereIn('name', $info['legacy'])->pluck('id'))->distinct()->pluck('role_id');
                    foreach ($roleIds as $roleId) {
                        DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission->id]);
                    }
                }
            }
            Role::where('name', 'Administrator')->where('system', true)->get()->each(fn ($role) => $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', array_keys(self::all()))->pluck('id')));
        });
    }
}
