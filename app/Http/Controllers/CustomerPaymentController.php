<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerLedgerService;
use App\Services\CustomerPaymentService;
use App\Support\Cheques;
use Illuminate\Http\Request;

class CustomerPaymentController extends Controller
{
    public function store(Request $request, Customer $customer)
    {
        abort_unless($request->user()->hasPermission('customers.collect_payment'), 403);
        $data = $request->validate(Cheques::rules() + ['token' => 'nullable|uuid', 'notes' => 'nullable|string|max:2000',
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'payment_date' => 'required|date',
            'mode' => 'required|in:auto,manual',
            'allocations' => 'array', 'allocations.opening_balance' => 'nullable|numeric|min:0|decimal:0,2', 'allocations.sales' => 'nullable|array|max:100', 'allocations.sales.*' => 'nullable|numeric|min:0|decimal:0,2',
        ]);

        $data['allocations']['opening_balance'] = $data['allocations']['opening_balance'] ?? '0';
        $data['allocations']['sales'] = array_filter($data['allocations']['sales'] ?? [], fn ($amount) => $amount !== null && $amount !== '');
        app(CustomerPaymentService::class)->record($customer, $data, $request->user()->id);

        return back()->with('success', 'Payment recorded successfully.');
    }

    public function ledger(Request $request, Customer $customer)
    {
        abort_unless($request->user()->hasPermission('customers.ledger'), 403);
        abort_if($request->filled('export') && ! $request->user()->hasPermission('customers.export'), 403);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $ledger = app(CustomerLedgerService::class)->build($customer, $startDate, $endDate);

        if ($request->input('export') === 'pdf') {
            return view('customers.ledger_print', compact('customer', 'ledger', 'startDate', 'endDate'));
        }

        if ($request->input('export') === 'csv') {
            $filename = "ledger_{$customer->name}.csv";
            $headers = [
                'Content-type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=$filename",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];
            $callback = function () use ($ledger) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Date', 'Type', 'Description', 'Debit', 'Credit', 'Balance']);
                foreach ($ledger as $row) {
                    fputcsv($file, [
                        $row['date']->format('Y-m-d H:i'),
                        $row['type'],
                        $row['description'],
                        $row['debit'],
                        $row['credit'],
                        $row['balance'],
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        return view('customers.ledger', compact('customer', 'ledger', 'startDate', 'endDate'));
    }
}
