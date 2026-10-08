<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResourceIndexRequest;
use App\Http\Requests\ResourceRequest;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\UnitPreset;
use App\Services\ResourceService;
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
                $query->whereRaw('(opening_due + '.Customer::invoiceDueSql().') '.($request->input('balance') === 'due' ? '>' : '=').' 0');
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
        $rows = $query->orderByDesc('id')->paginate(20)->withQueryString();

        if ($resource === 'customers') {
            $balanceQuery = Customer::withDueBalances()->toBase();
            $stats = DB::query()->fromSub($balanceQuery, 'customer_balances')->selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN opening_due + invoice_due > 0 THEN 1 ELSE 0 END) as due_count, SUM(opening_due + invoice_due) as total_due')->first();

            return view('customers.index', compact('resource', 'def', 'rows', 'stats'));
        }

        if ($resource === 'products') {
            $stats = Product::selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN active = 1 AND stock <= low_stock THEN 1 ELSE 0 END) as low_count, SUM(stock * cost) as stock_value')->first();

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

        if ($resource === 'customers') {
            $record->loadCount(['sales as completed_sales_count' => fn ($query) => $query->where('status', 'ACTIVE')]);
            $record->loadSum(['sales as paid_purchases' => fn ($query) => $query->where('status', 'ACTIVE')], 'customer_payable');
            $sales = $record->sales()->with('payments', 'returns', 'collections')->latest('sold_at')->paginate(10);

            return view('customers.show', compact('record', 'sales'));
        }

        if ($resource === 'products') {
            $movements = $record->hasMany(StockMovement::class)->with('user', 'sale', 'saleReturn.sale')->latest('created_at')->latest('id')->paginate(20, ['*'], 'movements')->withQueryString();

            return view('products.show', compact('record', 'movements'));
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
            if ($f[1] === 'select') {
                $query = $f[3]::query();
                $options[$key] = $query->orderBy('name')->pluck('name', 'id');
            }
        }
        if (in_array($resource, ['products', 'unit-presets'])) {
            $units = Unit::where('active', true)->orderBy('name')->get();
            $presets = UnitPreset::with(['unit', 'conversions.unit'])->orderBy('name')->get();
            $record->loadMissing('conversions.unit');
            $view = $resource === 'products' ? 'products.form' : 'products.presets.form';

            return view($view, compact('resource', 'def', 'record', 'options', 'units', 'presets'));
        }
        $permissions = Permission::orderBy('name')->get();

        return view($resource === 'customers' ? 'customers.form' : 'crud.form', compact('resource', 'def', 'record', 'options', 'permissions'));
    }

    public function store(ResourceRequest $request, string $resource, ResourceService $service)
    {
        $service->save($resource, $request->validated());

        return redirect()->route('manage.index', $resource)->with('success', 'Changes saved.');
    }

    public function update(ResourceRequest $request, string $resource, int $id, ResourceService $service)
    {
        $service->save($resource, $request->validated(), $id);

        return redirect()->route('manage.index', $resource)->with('success', 'Changes saved.');
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
