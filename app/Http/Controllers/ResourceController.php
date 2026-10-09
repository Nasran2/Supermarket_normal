<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResourceIndexRequest;
use App\Http\Requests\ResourceRequest;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\UnitPreset;
use App\Services\DefaultUnitService;
use App\Services\ResourceService;
use App\Services\StockLayerService;
use App\Support\Permissions;
use App\Support\Resources;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ResourceController extends Controller
{
    private function definition(string $resource, string $action): array
    {
        abort_unless(auth()->user()->hasPermission(Resources::permission($resource, $action)), 403);

        return Resources::get($resource);
    }

    public function index(ResourceIndexRequest $request, string $resource)
    {
        $def = $this->definition($resource, 'view');
        $query = $def['model']::with($def['relations'] ?? []);
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => collect($def['search'])->each(fn ($col) => $q->orWhere($col, 'like', '%'.$search.'%')));
        }
        if ($request->filled('active') && in_array($resource, ['products', 'units', 'payment-methods', 'payment-rules', 'users', 'suppliers', 'customers'])) {
            $query->where('active', $request->boolean('active'));
        }
        if ($resource === 'products' && $request->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'low_stock');
        }
        if ($resource === 'customers') {
            $query->withDueBalances();
            if ($request->filled('balance')) {
                $query->whereRaw('(opening_due - opening_due_paid + '.Customer::invoiceDueSql().') '.($request->input('balance') === 'due' ? '>' : '=').' 0');
            }
        }
        if ($resource === 'expenses') {
            if ($request->filled('from')) {
                $query->whereDate('expense_date', '>=', $request->input('from'));
            }
            if ($request->filled('to')) {
                $query->whereDate('expense_date', '<=', $request->input('to'));
            }
            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }
        }
        if ($resource === 'products') {
            $query->with(['stockLayers' => fn ($q) => $q->available()]);
        }
        if ($resource === 'suppliers') {
            $query->withDueBalances();
            $rows = $query->orderBy('name')->paginate(20)->withQueryString();
            $stats = DB::query()->fromSub(Supplier::withDueBalances()->toBase(), 'supplier_balances')->selectRaw('COUNT(*) as total, SUM(outstanding_due) as total_due, SUM(unrecorded_count) as unrecorded_count')->first();

            return view('suppliers.index', compact('rows', 'stats'));
        }
        if ($resource === 'roles') {
            $rows = $query->withCount('users')->orderBy('name')->paginate(20)->withQueryString();

            return view('roles.index', compact('rows'));
        }
        $rows = $query->orderByDesc('id')->paginate(20)->withQueryString();

        if ($resource === 'customers') {
            $balanceQuery = Customer::withDueBalances()->toBase();
            $stats = DB::query()->fromSub($balanceQuery, 'customer_balances')->selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN opening_due - opening_due_paid + invoice_due > 0 THEN 1 ELSE 0 END) as due_count, SUM(opening_due - opening_due_paid + invoice_due) as total_due')->first();

            return view('customers.index', compact('resource', 'def', 'rows', 'stats'));
        }

        if ($resource === 'products') {
            $stats = Product::selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN active = 1 AND stock <= low_stock THEN 1 ELSE 0 END) as low_count, 0 as stock_value')->first();

            $stats->stock_value = ProductStockLayer::available()->selectRaw('SUM(COALESCE(remaining_cost_total, remaining_quantity * cost_price)) as value')->value('value') ?? '0';

            return view('products.index', compact('resource', 'def', 'rows', 'stats'));
        }
        if ($resource === 'unit-presets') {
            return view('products.presets.index', compact('resource', 'def', 'rows'));
        }

        return view('crud.index', compact('resource', 'def', 'rows'));
    }

    public function create(string $resource)
    {
        $def = $this->definition($resource, 'create');
        $record = new $def['model'];

        return $this->form($resource, $def, $record);
    }

    public function edit(string $resource, int $id)
    {
        $def = $this->definition($resource, 'edit');
        $record = $def['model']::findOrFail($id);
        app(ResourceService::class)->guardEditable($resource, $record);

        return $this->form($resource, $def, $record);
    }

    public function show(string $resource, int $id)
    {
        $def = $this->definition($resource, 'view');
        $record = $def['model']::with($def['relations'] ?? [])->findOrFail($id);

        if ($resource === 'roles') {
            return view('roles.show', compact('record'));
        }
        if ($resource === 'suppliers') {
            return app(SupplierAccountController::class)->show(request(), $record);
        }
        if ($resource === 'categories') {
            $filters = request()->validate(['q' => 'nullable|string|max:150', 'active' => 'nullable|boolean']);
            $products = $record->products()->with('unit')->when(! empty($filters['q']), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%')->orWhere('barcode', 'like', '%'.$filters['q'].'%')))->when(isset($filters['active']), fn ($q) => $q->where('active', $filters['active']))->orderBy('name')->paginate(20)->withQueryString();

            return view('categories.show', compact('record', 'products'));
        }

        if ($resource === 'customers') {
            $record->loadCount(['sales as completed_sales_count' => fn ($query) => $query->where('status', 'ACTIVE')]);
            $record->loadSum(['sales as paid_purchases' => fn ($query) => $query->where('status', 'ACTIVE')], 'customer_payable');
            $sales = $record->sales()->with('payments', 'returns', 'collections')->latest('sold_at')->paginate(10);

            return view('customers.show', compact('record', 'sales'));
        }

        if ($resource === 'products') {
            $movements = $record->hasMany(StockMovement::class)->when(! auth()->user()->hasPermission('products.view_history'), fn ($q) => $q->whereRaw('1 = 0'))->with('user', 'sale', 'saleReturn.sale', 'layers.layer')->latest('created_at')->latest('id')->paginate(20, ['*'], 'movements')->withQueryString();

            app(StockLayerService::class)->ensureLegacy($record);
            $stockStatus = request()->validate(['stock_status' => 'nullable|in:available,depleted,all'])['stock_status'] ?? 'available';
            $layers = $record->stockLayers()->where('status', 'ACTIVE')->when($stockStatus === 'available', fn ($q) => $q->where('remaining_quantity', '>', 0))->when($stockStatus === 'depleted', fn ($q) => $q->where('remaining_quantity', '<=', 0))->orderBy('selling_price')->orderBy('received_at')->get();
            $pricing = $record->stockLayers()->available()->selectRaw('COUNT(DISTINCT selling_price) as prices, SUM(COALESCE(remaining_cost_total, remaining_quantity * cost_price)) as cost_value, SUM(remaining_quantity * selling_price) as selling_value')->first();

            return view('products.show', compact('record', 'movements', 'layers', 'pricing', 'stockStatus'));
        }
        if ($resource === 'unit-presets') {
            return view('products.presets.show', compact('record'));
        }

        return view('crud.show', compact('resource', 'def', 'record'));
    }

    private function form($resource, $def, $record)
    {
        $options = [];
        foreach ($def['fields'] as $key => $f) {
            if ($f[1] === 'select' || $f[1] === 'multiselect') {
                $query = $f[3]::query();
                if ($resource === 'users' && $key === 'role_id' && ! auth()->user()->isAdministrator()) {
                    $query->where(fn ($q) => $q->where('name', '!=', 'Administrator')->orWhere('system', false));
                }
                $options[$key] = $query->orderBy('name')->pluck('name', 'id');
            }
        }
        if (in_array($resource, ['products', 'unit-presets'])) {
            if ($resource === 'products' && ! $record->exists) {
                $record->unit_id = app(DefaultUnitService::class)->current()?->id;
            }
            $units = Unit::where('active', true)->orderBy('name')->get();
            $presets = UnitPreset::with(['unit', 'conversions.unit'])->orderBy('name')->get();
            $record->loadMissing('conversions.unit');
            $view = $resource === 'products' ? 'products.form' : 'products.presets.form';

            return view($view, compact('resource', 'def', 'record', 'options', 'units', 'presets'));
        }
        $moduleOrder = array_flip(['Dashboard', 'Point of sale', 'Sales', 'Purchases', 'Products', 'Stock adjustments', 'Daily register', 'Customers', 'Suppliers', 'Categories', 'Units', 'Multiple units', 'Expenses', 'Expense categories', 'Users', 'Roles & permissions', 'Settings']);
        $permissions = Permission::whereIn('name', array_keys(Permissions::all()))->get()->sortBy(fn ($p) => sprintf('%02d-%s-%03d', $moduleOrder[Permissions::info($p->name)['group']] ?? 18, Permissions::info($p->name)['group'], array_search($p->name, array_keys(Permissions::all()))));

        return view($resource === 'customers' ? 'customers.form' : 'crud.form', compact('resource', 'def', 'record', 'options', 'permissions'));
    }

    public function store(ResourceRequest $request, string $resource, ResourceService $service)
    {
        $record = $service->save($resource, $request->validated());

        if ($request->wantsJson()) {
            return response()->json($record, 201);
        }

        return ($request->user()->hasPermission(Resources::permission($resource, 'view'))
            ? redirect()->route('manage.index', $resource)
            : redirect()->route('manage.create', $resource))->with('success', 'Changes saved.');
    }

    public function update(ResourceRequest $request, string $resource, int $id, ResourceService $service)
    {
        $service->save($resource, $request->validated(), $id);

        return ($request->user()->hasPermission(Resources::permission($resource, 'view'))
            ? redirect()->route('manage.index', $resource)
            : redirect()->route('manage.edit', [$resource, $id]))->with('success', 'Changes saved.');
    }

    public function destroy(string $resource, int $id, ResourceService $service)
    {
        $this->definition($resource, 'delete');
        try {
            $service->delete($resource, $id);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'])) {
                return back()->withErrors(['delete' => 'This record is in use. Deactivate it to preserve transaction history.']);
            }
            throw $e;
        }

        return back()->with('success', 'Record removed.');
    }
}
