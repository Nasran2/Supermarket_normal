<?php

namespace App\Http\Controllers;

use App\Models\RepairBrand;
use App\Models\RepairModel;
use App\Support\Demo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepairCatalogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['brand' => 'nullable|integer', 'q' => 'nullable|string|max:120']);
        $brands = RepairBrand::withCount('models')->orderBy('name')->get();
        $brand = isset($filters['brand']) ? $brands->find($filters['brand']) : $brands->first();
        abort_if(isset($filters['brand']) && ! $brand, 404);
        $models = $brand?->models()->when($filters['q'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('repairs.catalog', compact('brands', 'brand', 'models'));
    }

    public function saveBrand(Request $request, ?RepairBrand $brand = null)
    {
        abort_if(Demo::enabled(), 403);
        $data = $request->validate(['name' => 'required|string|max:80', 'active' => 'required|boolean']);
        $brand ??= new RepairBrand;
        $this->save($brand, $data);

        return redirect()->route('repairs.catalog', ['brand' => $brand->id])->with('success', 'Brand saved.');
    }

    public function saveModel(Request $request, RepairBrand $brand, ?RepairModel $model = null)
    {
        abort_if(Demo::enabled(), 403);
        abort_if($model && $model->brand_id !== $brand->id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'active' => 'required|boolean',
            'inspection' => ['required', Rule::in(RepairModel::INSPECTIONS)],
        ]);
        $this->save($model ?? $brand->models()->make(), $data);

        return redirect()->route('repairs.catalog', ['brand' => $brand->id])->with('success', 'Model saved.');
    }

    private function save(RepairBrand|RepairModel $record, array $data): void
    {
        try {
            $record->fill($data + ['name_key' => mb_strtolower($data['name'])])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'That name is already in the catalog. Edit or reactivate the existing entry.']);
        }
    }
}
