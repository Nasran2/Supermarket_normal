<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ReturnAccountAllocation;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\SalesVisibility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesReturnService
{
    public function __construct(private StockReturnService $stock, private ReturnSettlementService $settlement) {}

    public function quote(Sale $sale, array $data, User $user, bool $lock = false, bool $historicalPreviewOnly = false): array
    {
        abort_unless(SalesVisibility::canSee($sale, $user), 403);
        $this->settlement->guard('sales', $data, $user, '0');
        if ($sale->status !== 'ACTIVE') {
            ReturnSettlementService::fail('A voided or cancelled invoice cannot be returned.');
        }
        if (! $sale->customer_id && ! app(SettingsService::class)->get('return_allow_anonymous', true)) {
            ReturnSettlementService::fail('Anonymous sale returns are disabled.');
        }
        $available = collect(app(SaleAftercareService::class)->returnableLines($sale))->keyBy(fn ($r) => $r['item']->id);
        $lines = [];
        $seen = [];
        foreach ($data['items'] as $input) {
            $id = $input['sale_item_id'];
            if (isset($seen[$id])) {
                ReturnSettlementService::fail('A return line may only appear once.');
            }
            $seen[$id] = true;
            $line = $available->get($id);
            if (! $line) {
                ReturnSettlementService::fail('This product does not belong to the original invoice.');
            }
            $qty = (string) $input['quantity'];
            if (Money::compare($qty, 0) === 0) {
                continue;
            }
            if (Money::compare($qty, 0) < 0 || Money::compare($qty, $line['remaining']) > 0) {
                ReturnSettlementService::fail('Return quantity exceeds the eligible remaining quantity for '.$line['item']->name.'.');
            }
            $item = $line['item'];
            $unit = $item->unitRecord ?? $item->product->unit;
            if (! $unit->allow_decimal && Money::compare($qty, Money::round($qty, 0)) !== 0) {
                ReturnSettlementService::fail($item->name.' requires whole quantities.');
            }
            $cumulative = Money::quantity($line['returned'], $qty);
            $base = Money::quantity($this->stock->portion($item->base_quantity ?? $item->quantity, $cumulative, $item->quantity, 3), '-'.Money::quantity('0', (string) $item->returns->sum('base_quantity')));
            if (Money::compare($base, 0) <= 0) {
                ReturnSettlementService::fail('Return quantity is below the supported stock precision for '.$item->name.'.');
            }
            $amount = Money::sub($this->stock->portion($line['net'], $cumulative, $item->quantity), Money::sum($item->returns->pluck('amount')));
            $action = $input['stock_action'] ?? app(SettingsService::class)->get('return_default_stock_action', 'RESTOCK');
            if (! in_array($action, ['RESTOCK', 'WRITEOFF', 'SUPPLIER'])) {
                ReturnSettlementService::fail('Choose a valid stock action.');
            }
            if ($action === 'WRITEOFF') {
                abort_unless($user->hasPermission('sales_returns.writeoff'), 403);
            }
            if ($action === 'SUPPLIER') {
                abort_unless($user->hasPermission('sales_returns.return_to_supplier'), 403);
                if (! app(SettingsService::class)->get('return_allow_supplier', true)) {
                    ReturnSettlementService::fail('Supplier returns are disabled.');
                }
            }
            app(StockLayerService::class)->legacyAllocation($item, $item->product);
            $allocations = $this->stock->salePlan($item, $base, $lock);
            if ($action === 'SUPPLIER') {
                foreach ($allocations as &$a) {
                    if (! $a['supplier_id']) {
                        abort_unless($user->hasPermission('supplier_returns.manage'), 403);
                        $supplier = Supplier::whereKey($input['supplier_id'] ?? null)->where('active', true)->first();
                        if (! $supplier) {
                            ReturnSettlementService::fail('Original supplier is unknown. Select a supplier for '.$item->name.'.');
                        }
                        $a['supplier_id'] = $supplier->id;
                    }
                }
            }
            unset($a);
            $lines[] = ['sale_item_id' => $item->id, 'product_id' => $item->product_id, 'unit_id' => $item->unit_id, 'quantity' => $qty, 'base_quantity' => $base, 'amount' => $amount, 'cost_total' => Money::sum(array_column($allocations, 'cost_total')), 'stock_action' => $action, 'reason' => $input['reason'] ?? $data['reason'], 'name' => $item->name, 'unit' => $item->unit, 'allocations' => $allocations];
        }
        if (! $lines) {
            ReturnSettlementService::fail('Enter a positive return quantity for at least one product.');
        }
        $amount = Money::sum(array_column($lines, 'amount'));
        $settings = app(SettingsService::class);
        $fee = '0.00';
        $policy = $settings->get('return_fee_policy', 'NONE');
        $priorFees = Money::add(Money::sum($sale->returns->pluck('fee_refund')), (string) SaleReturnItem::whereIn('sale_item_id', $sale->items()->select('id'))->whereHas('return', fn ($q) => $q->completed()->where('return_type', 'NO_RECEIPT'))->sum('fee_refund'));
        if ($policy === 'FULL') {
            $fee = Money::sub($sale->customer_fees, $priorFees);
        }
        if ($policy === 'PRO_RATA' && Money::compare($sale->sale_amount, 0) > 0) {
            $fee = Money::sub($this->stock->portion($sale->customer_fees, Money::add($sale->returned_total, $amount), $sale->sale_amount), $priorFees);
        }
        $this->settlement->guard('sales', $data, $user, Money::add($amount, $fee));
        $resolution = $data['resolution'] ?? 'MONEY';
        $replacementQuote = null;
        $returnCredits = [];
        foreach ($lines as $line) {
            if ($line['stock_action'] === 'RESTOCK') {
                foreach ($line['allocations'] as $a) {
                    $id = $a['stock_layer_id'];
                    $returnCredits[$id] = ['quantity' => Money::quantity($returnCredits[$id]['quantity'] ?? '0', $a['quantity']), 'cost_total' => Money::add($returnCredits[$id]['cost_total'] ?? '0', $a['cost_total'])];
                }
            }
        }
        if ($resolution !== 'MONEY') {
            $replacementInputs = $data['replacements'] ?? [];
            if (! $replacementInputs) {
                ReturnSettlementService::fail('Select replacement products.');
            }
            $same = $resolution === 'SAME' && ! ($data['use_current_price'] ?? false);
            if (($data['use_current_price'] ?? false)) {
                abort_unless($user->hasPermission('pos.override_price'), 403);
            }
            if ($resolution === 'SAME') {
                $used = [];
                foreach ($replacementInputs as &$replacement) {
                    $r = collect($lines)->firstWhere('sale_item_id', $replacement['sale_item_id'] ?? null);
                    if (! $r || isset($used[$r['sale_item_id']])) {
                        ReturnSettlementService::fail('Select exactly one replacement for each returned product.');
                    }
                    $used[$r['sale_item_id']] = true;
                    $original = $available[$r['sale_item_id']]['item'];
                    $replacement['product_id'] = $original->product_id;
                    $replacement['unit_id'] = $original->unit_id;
                    $replacement['quantity'] = $r['quantity'];
                    if ($same) {
                        $replacement['unit_price'] = (string) BigDecimal::of($r['amount'])->dividedBy($r['quantity'], 2, RoundingMode::CEILING);
                    }
                }
                unset($replacement);
                if (count($used) !== count($lines)) {
                    ReturnSettlementService::fail('Select replacements for every returned product.');
                }
            }
            $quoteData = ['items' => $replacementInputs, 'payments' => []];
            $replacementQuote = app(SaleService::class)->quote($quoteData, $user, $lock, null, $same, $returnCredits);
            if ($same) {
                $quoteData['discount'] = Money::sub($replacementQuote['subtotal'], $amount);
                $replacementQuote = app(SaleService::class)->quote($quoteData, $user, $lock, null, true, $returnCredits);
            }
        } elseif (! empty($data['replacements'])) {
            ReturnSettlementService::fail('Money back cannot include replacement products.');
        }
        $difference = $this->settlement->difference(Money::add($amount, $fee), $sale->due_balance, $replacementQuote['sale_amount'] ?? '0.00');
        if ($historicalPreviewOnly) {
            return $difference + ['lines' => $lines, 'amount' => $amount, 'fee_refund' => $fee, 'cost_total' => Money::sum(array_column($lines, 'cost_total'))];
        }
        $apply = Money::round((string) ($data['apply_due'] ?? 0));
        if (Money::compare($apply, 0) < 0 || Money::compare($apply, $difference['owed']) > 0) {
            ReturnSettlementService::fail('Due allocation exceeds the available credit.');
        }
        if (Money::compare($apply, 0) > 0) {
            abort_unless($user->hasPermission('sales_returns.apply_customer_due'), 403);
            if (empty($data['credit_customer_id'] ?? $sale->customer_id)) {
                ReturnSettlementService::fail('Select the customer whose due should be reduced.');
            }
        }
        $customerDue = '0.00';
        $target = (int) ($data['credit_customer_id'] ?? $sale->customer_id ?? 0);
        if ($target && $user->hasPermission('sales_returns.apply_customer_due')) {
            if ($target !== $sale->customer_id) {
                abort_unless($user->hasPermission('sales_returns.allocate_other_customer'), 403);
                if (! app(SettingsService::class)->get('return_customer_search', true)) {
                    ReturnSettlementService::fail('Customer search for return credit is disabled.');
                }
            }
            $customer = Customer::whereKey($target)->where('active', true)->firstOrFail();
            $openingCredits = (string) ReturnAccountAllocation::where('customer_id', $target)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount');
            $customerDue = Money::sub(Money::sub($customer->opening_due, $customer->opening_due_paid), $openingCredits);
            foreach ($customer->sales()->visibleTo($user)->where('status', 'ACTIVE')->where('id', '!=', $sale->id)->get() as $other) {
                $customerDue = Money::add($customerDue, $other->due_balance);
            }
            if (Money::compare($apply, $customerDue) > 0) {
                ReturnSettlementService::fail('Customer due allocation exceeds the other eligible outstanding due.');
            }
        }
        if (Money::compare($apply, 0) > 0 && ! app(SettingsService::class)->get('return_apply_customer_due', true)) {
            ReturnSettlementService::fail('Applying return credit to customer due is disabled.');
        }
        $refund = Money::sub($difference['owed'], $apply);
        if (Money::compare($refund, 0) > 0) {
            abort_unless($user->hasPermission('sales_returns.refund'), 403);
        }
        if (($data['add_to_due'] ?? false) && Money::compare($difference['must_pay'], 0) > 0) {
            abort_unless($user->hasPermission('pos.due_sale'), 403);
            if (! $sale->customer_id) {
                ReturnSettlementService::fail('Select a named original customer before adding the exchange difference to due.');
            }
        }
        $result = $difference + ['amount' => $amount, 'fee_refund' => $fee, 'lines' => $lines, 'cost_total' => Money::sum(array_column($lines, 'cost_total')), 'replacement_quote' => $replacementQuote, 'refund_amount' => $refund, 'apply_due' => $apply, 'resolution' => $resolution, 'customer_due_available' => $customerDue];
        $result['quote_hash'] = $this->hash($result, $data);

        return $result;
    }

    private function hash(array $q, array $data): string
    {
        $snapshot = array_intersect_key($q, array_flip(['return_value', 'due_reduction', 'replacement_value', 'must_pay', 'owed', 'refund_amount', 'apply_due', 'fee_refund', 'keep_credit']));
        $snapshot['input'] = array_diff_key($data, ['token' => true, 'quote_hash' => true]);
        $method = PaymentMethod::find($data['payment_method_id'] ?? null);
        $snapshot['payment_policy'] = $method?->only(['active', 'type', 'has_charge', 'charge_type', 'charge_value', 'charge_bearer', 'charge_minimum_amount']);

        return hash_hmac('sha256', json_encode($snapshot), config('app.key'));
    }

    public function complete(Sale $original, array $data, User $user): SaleReturn
    {
        abort_unless($user->hasPermission('sales_returns.create'), 403);

        return DB::transaction(function () use ($original, $data, $user) {
            $sale = Sale::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($sale->customer_id) {
                Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
            }
            $existing = SaleReturn::where('token', $data['token'])->first();
            if ($existing) {
                abort_unless($existing->sale_id === $sale->id && $existing->user_id === $user->id, 403);

                if ($existing->status !== 'DRAFT') {
                    return $existing;
                }
            }
            Product::whereIn('id', $sale->items()->pluck('product_id')->merge(array_column($data['replacements'] ?? [], 'product_id')))->orderBy('id')->lockForUpdate()->get();
            $q = $this->quote($sale, $data, $user, true);
            if (! empty($data['quote_hash']) && ! hash_equals($q['quote_hash'], $data['quote_hash'])) {
                ReturnSettlementService::fail('Stock, balances or payment settings changed. Review the return again.');
            }
            $register = app(RegisterService::class)->current($user->id, true);
            $fields = ['sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'register_id' => $register?->id, 'user_id' => $user->id, 'token' => $data['token'], 'reference' => $existing?->reference ?? app(DocumentNumberService::class)->next('SALES_RETURN', now()), 'reason' => $data['reason'] ?? '', 'notes' => $data['notes'] ?? null, 'amount' => $q['amount'], 'fee_refund' => $q['fee_refund'], 'cost_total' => $q['cost_total'], 'due_reduction' => $q['due_reduction'], 'refund_amount' => $q['refund_amount'], 'customer_due_applied' => $q['apply_due'], 'replacement_value' => $q['replacement_value'], 'resolution' => $q['resolution'], 'returned_at' => now(), 'approved_by' => $user->hasPermission('sales_returns.approve') ? $user->id : null];
            $fields += ['return_type' => 'INVOICE', 'verification_status' => 'VERIFIED', 'additional_payment' => '0.00', 'verified_amount' => $q['amount'], 'historical_cost_total' => $q['cost_total'], 'approved_at' => $user->hasPermission('sales_returns.approve') ? now() : null];
            $fields['status'] = 'COMPLETED';
            $fields['draft_payload'] = null;
            if ($existing) {
                $existing->update($fields);
                $r = $existing;
            } else {
                $r = SaleReturn::create($fields);
            }
            foreach ($q['lines'] as $line) {
                $item = $r->items()->create(array_diff_key($line, ['allocations' => true]));
                $this->stock->applySale($item, $line['allocations'], $line['stock_action'], $r->reference, $user->id);
                if ($line['stock_action'] === 'WRITEOFF') {
                    $cat = ExpenseCategory::firstOrCreate(['name' => 'Sales Return Write-Off'], ['system' => true]);
                    Expense::create(['expense_category_id' => $cat->id, 'user_id' => $user->id, 'sale_id' => $sale->id, 'type' => 'RETURN_WRITEOFF', 'expense_date' => today(), 'reference' => $r->reference, 'description' => $line['reason'].' · '.$sale->invoice.' · '.$line['name'].' × '.$line['quantity'], 'amount' => $line['cost_total']]);
                }
                if ($line['stock_action'] === 'SUPPLIER') {
                    foreach ($line['allocations'] as $a) {
                        $supplierReturn = $r->supplierReturns()->where('supplier_id', $a['supplier_id'])->first();
                        $supplierReturn ??= SupplierReturn::create(['reference' => app(DocumentNumberService::class)->next('SUPPLIER_RETURN', now()), 'sale_return_id' => $r->id, 'supplier_id' => $a['supplier_id'], 'user_id' => $user->id, 'notes' => 'Original invoice '.$sale->invoice]);
                        $supplierReturn->update(['amount' => Money::add((string) ($supplierReturn->amount ?? '0'), $a['cost_total'])]);
                        $item->allocations()->where('sale_stock_allocation_id', $a['allocation_id'])->update(['supplier_return_id' => $supplierReturn->id]);
                    }
                }
            }
            $this->settleDocument($r, $q, $data, $user, $sale->customer_id);
            Audit::record('sales_return.complete', $r, [], $r->load('items.allocations', 'settlements', 'allocations', 'supplierReturns')->toArray());

            return $r;
        }, 3);
    }

    public function settleDocument(SaleReturn $r, array $q, array $data, User $user, ?int $customerId): void
    {
        if ($q['replacement_quote']) {
            $exchange = app(SaleService::class)->exchange($q['replacement_quote'], $customerId, $user, (string) Str::uuid(), $r->reference);
            $r->update(['replacement_sale_id' => $exchange->id]);
            $credit = ReturnSettlementService::minimum($q['available_credit'], $q['replacement_value']);
            if (Money::compare($credit, 0) > 0) {
                ReturnAccountAllocation::create(['sale_return_id' => $r->id, 'sale_id' => $exchange->id, 'customer_id' => $customerId, 'kind' => 'EXCHANGE_CREDIT', 'amount' => $credit]);
            }
            if (Money::compare($q['must_pay'], 0) > 0 && ! ($data['add_to_due'] ?? false)) {
                $event = $this->settlement->money($r, 'sale_return', 'EXCHANGE_PAYMENT', $q['must_pay'], $data['payment_method_id'] ?? null, $user, false);
                $method = PaymentMethod::findOrFail($event->payment_method_id);
                $charge = app(PaymentChargeService::class)->calculateCharge($method, $q['must_pay'], true, $q['replacement_value']);
                $payment = $exchange->payments()->create(['payment_method_id' => $method->id, 'method_name' => $method->name, 'method_type' => $method->type, 'sale_amount' => $q['must_pay'], 'processing_charge' => $charge['processing_charge'], 'charge_type' => $charge['charge_type'], 'charge_value' => $charge['charge_value'], 'charge_bearer' => $charge['charge_bearer'], 'customer_payable' => $charge['customer_payable'], 'amount_paid' => $charge['customer_payable'], 'change' => '0.00', 'reference' => $r->reference]);
                $exchange->update(['processing_charge' => $charge['processing_charge'], 'customer_payable' => Money::add($q['replacement_value'], Money::sub($charge['customer_payable'], $q['must_pay']))]);
                $event->update(['amount' => $charge['customer_payable'], 'processing_charge' => $charge['processing_charge']]);
                app(PaymentChargeService::class)->createProcessingExpense($payment);
                $r->update(['additional_payment' => $charge['customer_payable']]);
            }
        }
        if (Money::compare($q['apply_due'], 0) > 0) {
            $this->settlement->applyCustomer($r, (int) ($data['credit_customer_id'] ?? $customerId), $q['apply_due'], $user);
        }
        if (Money::compare($q['refund_amount'], 0) > 0) {
            $event = $this->settlement->money($r, 'sale_return', ($r->return_type === 'NO_RECEIPT' ? 'NO_RECEIPT_SALES_RETURN_REFUND' : 'SALES_RETURN_REFUND'), '-'.$q['refund_amount'], $data['payment_method_id'] ?? null, $user, false);
            $r->update(['payment_method_id' => $event->payment_method_id, 'method_name' => $event->method_name, 'method_type' => $event->method_type, 'register_id' => $event->register_id]);
        }
    }

    public function cancel(SaleReturn $original, string $reason, User $user): void
    {
        if ($original->return_type === 'NO_RECEIPT') {
            app(NoReceiptSalesReturnService::class)->cancel($original, $reason, $user);

            return;
        }
        abort_unless($user->hasPermission('sales_returns.cancel'), 403);
        DB::transaction(function () use ($original, $reason, $user) {
            $r = SaleReturn::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $sale = Sale::whereKey($r->sale_id)->lockForUpdate()->firstOrFail();
            abort_unless(SalesVisibility::canSee($sale, $user), 403);
            if ($r->status === 'DRAFT') {
                $r->update(['status' => 'CANCELLED', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
                Audit::record('return.draft_cancel', $r, [], ['reason' => $reason]);

                return;
            }
            if ($r->status !== 'COMPLETED') {
                ReturnSettlementService::fail('This return has already been cancelled.');
            }
            if ($r->items()->whereDoesntHave('allocations')->exists()) {
                ReturnSettlementService::fail('This historical return predates allocation auditing and requires reconciliation before cancellation.');
            }
            if ($r->supplierReturns()->whereNotIn('status', ['PENDING', 'CANCELLED'])->exists()) {
                ReturnSettlementService::fail('The supplier return has been sent or settled and cannot be cancelled from the customer return.');
            }

            if ($r->replacement) {
                $exchange = Sale::whereKey($r->replacement_sale_id)->lockForUpdate()->firstOrFail();
                if ($exchange->returns()->exists() || $exchange->collections()->exists()) {
                    ReturnSettlementService::fail('Replacement invoice has subsequent activity. Cancel that activity first.');
                }
                foreach ($exchange->items as $item) {
                    $p = Product::with('unit')->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                    app(StockLayerService::class)->restore($p, $item, $item->base_quantity, 'REVERSE_EXCHANGE', $r->reference, $user->id);
                }
                $exchange->update(['status' => 'RETURN_CANCELLED', 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
                Expense::where('sale_id', $exchange->id)->where('type', 'AUTOMATIC')->update(['status' => 'REVERSED']);
            }
            $this->stock->reverse($r, true, $user->id);
            $this->settlement->reverseMoney($r, 'sale_return', $user);
            Expense::where('reference', $r->reference)->where('type', 'RETURN_WRITEOFF')->update(['status' => 'REVERSED']);
            $r->supplierReturns()->update(['status' => 'CANCELLED']);
            $r->update(['status' => 'CANCELLED', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            Audit::record('sales_return.cancel', $r, [], ['reason' => $reason]);
        }, 3);
    }
}
