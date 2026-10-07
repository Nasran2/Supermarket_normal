<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaleEditRequest;
use App\Http\Requests\VoidRequest;
use App\Models\Customer;
use App\Models\Register;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date', 'to' => 'nullable|date', 'status' => 'nullable|in:ACTIVE,VOIDED']);
        $query = Sale::with(['user', 'customer', 'payment']);
        if ($request->filled('q')) {
            $query->where('invoice', 'like', '%'.$data['q'].'%');
        }
        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $data['from']);
        }
        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $data['to']);
        }
        if ($request->filled('status')) {
            $query->where('status', $data['status']);
        }
        $sales = $query->latest('sold_at')->paginate(20)->withQueryString();

        return view('sales.index', compact('sales'));
    }

    public function show(Sale $sale)
    {
        $sale->load(['items', 'user', 'customer', 'payment.expense', 'voider']);
        $customers = Customer::where('active', true)->orderBy('name')->get();

        return view('sales.show', compact('sale', 'customers'));
    }

    public function receipt(Sale $sale)
    {
        abort_unless(auth()->user()->hasPermission('sales.view') || ($sale->user_id === auth()->id() && auth()->user()->hasPermission('sales.create')), 403);
        $sale->load(['items', 'user', 'customer', 'payment']);

        return view('sales.receipt', compact('sale'));
    }

    public function update(SaleEditRequest $request, Sale $sale)
    {
        DB::transaction(function () use ($request, $sale) {
            $register = Register::whereKey($sale->register_id)->lockForUpdate()->firstOrFail();
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'ACTIVE' || $register->closed_at) {
                throw ValidationException::withMessages(['sale' => 'Only active sales in open registers can be edited.']);
            }
            $before = $sale->load('payment')->toArray();
            $data = $request->validated();
            $sale->update(['customer_id' => $data['customer_id'] ?? null, 'notes' => $data['notes'] ?? null]);
            $sale->payment()->update(['reference' => $data['reference'] ?? null]);
            Audit::record('sale.edit', $sale, $before, $data);
        });

        return back()->with('success', 'Sale details updated.');
    }

    public function void(VoidRequest $request, Sale $sale, SaleService $service)
    {
        $service->void($sale, $request->validated('reason'), auth()->id());

        return back()->with('success', 'Sale voided. Stock restored and processing expense reversed.');
    }
}
