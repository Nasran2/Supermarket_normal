<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Support\Money;

class ReportService
{
    public const TITLES = ['sales' => 'Sales', 'purchases' => 'Purchases', 'expenses' => 'Expenses', 'profit' => 'Profit & loss', 'stock' => 'Stock', 'product-sales' => 'Product sales', 'payments' => 'Payment methods', 'register' => 'Register', 'cash' => 'Cash summary', 'payment-charges' => 'Payment processing charges', 'card-charges' => 'Card charges', 'qr-charges' => 'QR charges', 'bank-charges' => 'Bank charges', 'audit' => 'Audit log'];

    public function build(string $kind, array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $cards = [];
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';
        $pay = SalePayment::with(['sale.user', 'expense'])->whereHas('sale', fn ($q) => $q->where('status', 'ACTIVE')->whereBetween('sold_at', [$start, $end]));
        foreach (['payment_method_id' => 'payment_method_id', 'rule_id' => 'payment_charge_rule_id', 'charge_bearer' => 'charge_bearer'] as $input => $col) {
            if (! empty($filters[$input])) {
                $pay->where($col, $filters[$input]);
            }
        }
        if (! empty($filters['user_id'])) {
            $pay->whereHas('sale', fn ($q) => $q->where('user_id', $filters['user_id']));
        }
        if (in_array($kind, ['payment-charges', 'card-charges', 'qr-charges', 'bank-charges'])) {
            if ($kind !== 'payment-charges') {
                $pay->where('method_type', match ($kind) {
                    'card-charges' => 'CARD','qr-charges' => 'QR',default => 'BANK_TRANSFER'
                });
            }
            $query = $pay->where('processing_charge', '>', 0)->latest('id');
            $headers = ['Date', 'Invoice', 'Payment method', 'Sale amount', 'Rule', 'Charge', 'Fee', 'Paid by', 'Expense reference', 'Cashier'];
            $map = fn ($p) => [$p->sale->sold_at->format('Y-m-d H:i'), $p->sale->invoice, $p->method_name, Money::display($p->sale_amount), $p->rule_name, $p->charge_type === 'PERCENTAGE' ? rtrim(rtrim($p->charge_value, '0'), '.').'%' : Money::display($p->charge_value).' fixed', Money::display($p->processing_charge), ucfirst(strtolower($p->charge_bearer)), $p->expense ? 'EXP-'.$p->expense->id : '—', $p->sale->user->name];
            $cards = ['Total charges' => (string) (clone $query)->sum('processing_charge'), 'Customer paid' => (string) (clone $query)->where('charge_bearer', 'CUSTOMER')->sum('processing_charge'), 'Business paid' => (string) (clone $query)->where('charge_bearer', 'BUSINESS')->sum('processing_charge'), 'Card charges' => (string) (clone $query)->where('method_type', 'CARD')->sum('processing_charge'), 'QR charges' => (string) (clone $query)->where('method_type', 'QR')->sum('processing_charge')];
        } elseif ($kind === 'payments') {
            $query = (clone $pay)->selectRaw('payment_method_id, method_name, method_type, COUNT(*) as transactions, SUM(sale_amount) as base, SUM(CASE WHEN charge_bearer = ? THEN processing_charge ELSE 0 END) as customer_fees, SUM(CASE WHEN charge_bearer = ? THEN processing_charge ELSE 0 END) as business_fees, SUM(customer_payable) as collections', ['CUSTOMER', 'BUSINESS'])->groupBy('payment_method_id', 'method_name', 'method_type')->orderBy('method_name');
            $headers = ['Payment method', 'Type', 'Transactions', 'Base sales', 'Customer-paid charges', 'Business-paid charges', 'Customer collections'];
            $map = fn ($p) => [$p->method_name, $p->method_type, $p->transactions, Money::display($p->base), Money::display($p->customer_fees), Money::display($p->business_fees), Money::display($p->collections)];
            $cards = ['Collections' => (string) (clone $pay)->sum('customer_payable'), 'Base sales' => (string) (clone $pay)->sum('sale_amount')];
        } elseif ($kind === 'sales') {
            $query = Sale::with(['user', 'customer', 'payment'])->whereBetween('sold_at', [$start, $end])->latest('sold_at');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['payment_method_id'])) {
                $query->whereHas('payment', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
            }
            if (! empty($filters['q'])) {
                $query->where('invoice', 'like', '%'.$filters['q'].'%');
            }
            $headers = ['Date', 'Invoice', 'Cashier', 'Customer', 'Subtotal', 'Discount', 'Processing charge', 'Base sales', 'Customer payable', 'Method', 'Status'];
            $map = fn ($s) => [$s->sold_at->format('Y-m-d H:i'), $s->invoice, $s->user->name, $s->customer?->name ?? 'Walk-in', Money::display($s->subtotal), Money::display($s->discount), Money::display($s->processing_charge), Money::display($s->sale_amount), Money::display($s->customer_payable), $s->payment->method_name, $s->status];
            $cards = ['Active revenue' => (string) (clone $query)->where('status', 'ACTIVE')->sum('sale_amount'), 'Active collections' => (string) (clone $query)->where('status', 'ACTIVE')->sum('customer_payable')];
        } elseif ($kind === 'purchases') {
            $query = Purchase::with('supplier', 'user')->whereBetween('purchase_date', [$from, $to])->latest('purchase_date');
            $headers = ['Date', 'Reference', 'Supplier', 'Amount', 'Recorded by', 'Status'];
            $map = fn ($p) => [$p->purchase_date->format('Y-m-d'), $p->reference, $p->supplier->name, Money::display($p->total), $p->user->name, $p->status];
            $cards = ['Active purchases' => (string) (clone $query)->where('status', 'ACTIVE')->sum('total')];
        } elseif ($kind === 'expenses') {
            $query = Expense::with('category', 'user', 'method')->whereBetween('expense_date', [$from, $to])->latest('expense_date');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['payment_method_id'])) {
                $query->where('payment_method_id', $filters['payment_method_id']);
            }
            $headers = ['Date', 'Reference', 'Category', 'Description', 'Amount', 'Type', 'Status', 'Method', 'Recorded by'];
            $map = fn ($e) => [$e->expense_date->format('Y-m-d'), $e->reference ?? 'EXP-'.$e->id, $e->category->name, $e->description, Money::display($e->amount), $e->type, $e->status, $e->method?->name ?? '—', $e->user->name];
            $cards = ['Active expenses' => (string) (clone $query)->where('status', 'ACTIVE')->sum('amount')];
        } elseif ($kind === 'stock') {
            $query = Product::with('unit', 'category')->orderBy('name');
            if (! empty($filters['q'])) {
                $query->where('name', 'like', '%'.$filters['q'].'%');
            }
            $headers = ['Product', 'SKU', 'Barcode', 'Category', 'Unit', 'Stock', 'Low stock level', 'Cost', 'Price', 'Status'];
            $map = fn ($p) => [$p->name, $p->sku, $p->barcode, $p->category->name, $p->unit->short_name, $p->stock, $p->low_stock, Money::display($p->cost), Money::display($p->price), $p->active ? 'Active' : 'Inactive'];
            $cards = ['Stock at cost' => (string) (clone $query)->reorder()->selectRaw('SUM(stock * cost) as value')->value('value')];
        } elseif ($kind === 'product-sales') {
            $query = SaleItem::whereHas('sale', fn ($q) => $q->where('status', 'ACTIVE')->whereBetween('sold_at', [$start, $end]))->selectRaw('product_id, name, unit, SUM(quantity) as quantity, SUM(total) as total')->groupBy('product_id', 'name', 'unit')->orderByDesc('total');
            $headers = ['Product', 'Unit', 'Quantity', 'Line sales before invoice discount'];
            $map = fn ($i) => [$i->name, $i->unit, $i->quantity, Money::display($i->total)];
        } elseif (in_array($kind, ['register', 'cash'])) {
            $query = app(RegisterService::class)->withSummary(Register::with('user'))->whereBetween('opened_at', [$start, $end])->latest('opened_at');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            $headers = ['Register', 'Cashier', 'Opened', 'Closed', 'Opening cash', 'Cash sales', 'Cash in', 'Cash out', 'Cash expenses', 'Expected', 'Actual', 'Difference'];
            $map = function ($r) {
                $s = app(RegisterService::class)->summary($r);

                return ['REG-'.$r->id, $r->user->name, $r->opened_at->format('Y-m-d H:i'), $r->closed_at?->format('Y-m-d H:i') ?? 'Open', Money::display($r->opening_cash), Money::display($s['cash']), Money::display($s['in']), Money::display($s['out']), Money::display($s['expenses']), Money::display($r->closed_at ? $r->expected_cash : $s['expected']), $r->closed_at ? Money::display($r->actual_cash) : '—', $r->closed_at ? Money::display($r->difference) : '—'];
            };
        } elseif ($kind === 'audit') {
            $query = AuditLog::with('user')->whereBetween('created_at', [$start, $end])->latest();
            $headers = ['Time', 'User', 'Action', 'Record', 'Before', 'After'];
            $map = fn ($a) => [$a->created_at->format('Y-m-d H:i:s'), $a->user?->name ?? 'System', $a->action, class_basename($a->subject_type).' #'.$a->subject_id, json_encode($a->before), json_encode($a->after)];
        } else {
            abort(404);
        }

        return compact('query', 'headers', 'map', 'cards');
    }
}
