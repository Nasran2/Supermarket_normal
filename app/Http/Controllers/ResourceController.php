<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResourceIndexRequest;
use App\Http\Requests\ResourceRequest;
use App\Models\Permission;
use App\Services\ResourceService;
use App\Support\Resources;
use Illuminate\Database\QueryException;

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
        $permissions = Permission::orderBy('name')->get();

        return view('crud.form', compact('resource', 'def', 'record', 'options', 'permissions'));
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
