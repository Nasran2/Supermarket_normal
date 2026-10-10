<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\ReturnAccountAllocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
{
    public function __construct(private StockReturnService $stock, private ReturnSettlementService $settlement) {}

    public function quote(Purchase $purchase, array $data, User $user, bool $lock = false): array
    {
        $this->settlement->guard('purchase', $data, $user, '0');
        if ($purchase->status !== 'ACTIVE') {
            ReturnSettlementService::fail('Voided purchases cannot be returned.');
        }
        if (! $purchase->payment_tracking) {
            ReturnSettlementService::fail('Set the historical purchase payment balance before recording a return.');
        }
        $lines = [];
        $seen = [];
        foreach ($data['items'] as $input) {
            $id = $input['purchase_item_id'];
            if (isset($seen[$id])) {
                ReturnSettlementService::fail('A purchase line may only appear once.');
            }
            $seen[$id] = true;
            $item = $purchase->items()->with('product.unit')->find($id);
            if (! $item) {
                ReturnSettlementService::fail('The returned product does not belong to this purchase.');
            }
            $qty = (string) $input['quantity'];
            if (Money::compare($qty, 0) === 0) {
                continue;
            }
            $prior = PurchaseReturnItem::where('purchase_item_id', $id)->whereHas('return', fn ($q) => $q->completed())->get();
            $returned = Money::quantity('0', (string) $prior->sum('quantity'));
            if (Money::compare($qty, 0) < 0 || Money::compare(Money::quantity($returned, $qty), $item->quantity) > 0) {
                ReturnSettlementService::fail('Quantity exceeds the remaining original purchase quantity.');
            }
            $unit = Unit::find($item->unit_id) ?? $item->product->unit;
            if (! $unit->allow_decimal && Money::compare($qty, Money::round($qty, 0)) !== 0) {
                ReturnSettlementService::fail($item->name.' requires whole quantities.');
            }
            $base = Money::quantity($this->stock->portion($item->base_quantity ?? $item->quantity, Money::quantity($returned, $qty), $item->quantity, 3), '-'.Money::quantity('0', (string) $prior->sum('base_quantity')));
            if (Money::compare($base, 0) <= 0) {
                ReturnSettlementService::fail('Return quantity is below the supported stock precision for '.$item->name.'.');
            }
            $rows = $this->stock->purchasePlan($item, $base, $lock);
            $amount = Money::sub($this->stock->portion($item->total, Money::quantity($returned, $qty), $item->quantity), Money::sum($prior->pluck('amount')));
            $lines[] = ['purchase_item_id' => $id, 'quantity' => $qty, 'base_quantity' => $base, 'amount' => $amount, 'cost_total' => Money::sum(array_column($rows, 'cost_total')), 'name' => $item->name, 'unit' => $item->unit, 'allocations' => $rows];
        }
        if (! $lines) {
            ReturnSettlementService::fail('Enter a positive return quantity.');
        }
        $amount = Money::sum(array_column($lines, 'amount'));
        $this->settlement->guard('purchase', $data, $user, $amount);
        $resolution = $data['resolution'] ?? 'MONEY';
        $replacements = [];
        if ($resolution !== 'MONEY') {
            $inputs = $data['replacements'] ?? [];
            if (! $inputs) {
                ReturnSettlementService::fail('Enter incoming replacement products.');
            }
            $used = [];
            foreach ($inputs as $input) {
                if ($resolution === 'SAME') {
                    $line = collect($lines)->firstWhere('purchase_item_id', $input['purchase_item_id'] ?? null);
                    if (! $line || isset($used[$line['purchase_item_id']])) {
                        ReturnSettlementService::fail('Select one replacement for each returned product.');
                    }
                    $used[$line['purchase_item_id']] = true;
                    $original = $purchase->items->find($line['purchase_item_id']);
                    $input['product_id'] = $original->product_id;
                    $input['unit_id'] = $original->unit_id;
                    $input['quantity'] = $line['quantity'];
                    $input['cost'] = $input['cost'] ?? $original->cost;
                }
                $product = Product::with(['unit', 'conversions.unit'])->whereKey($input['product_id'])->where('active', true)->firstOrFail();
                $selected = app(ProductUnitService::class)->resolve($product, $input['unit_id'] ?? null, (string) $input['quantity']);
                $cost = Money::round((string) ($input['cost'] ?? $product->cost));
                $price = Money::round((string) ($input['selling_price'] ?? $selected['price']));
                if (Money::compare($cost, 0) < 0 || Money::compare($price, 0) < 0) {
                    ReturnSettlementService::fail('Replacement prices cannot be negative.');
                }
                if (Money::compare($price, $selected['price']) !== 0) {
                    abort_unless($user->hasPermission('purchases.manage_prices'), 403);
                }
                if ($resolution === 'SAME' && Money::compare($cost, $original->cost) !== 0) {
                    abort_unless($user->hasPermission('purchases.manage_prices'), 403);
                }
                $replacements[] = ['product_id' => $product->id, 'quantity' => (string) $input['quantity'], 'unit_id' => $selected['id'], 'cost' => $cost, 'selling_price' => $price, 'total' => Money::mul($cost, (string) $input['quantity'])];
            }
            if ($resolution === 'SAME' && count($used) !== count($lines)) {
                ReturnSettlementService::fail('Select replacements for all returned products.');
            }
        } elseif (! empty($data['replacements'])) {
            ReturnSettlementService::fail('Money / credit back cannot include replacement products.');
        }
        $difference = $this->settlement->difference($amount, $purchase->due_amount, Money::sum(array_column($replacements, 'total')));
        $apply = Money::round((string) ($data['apply_due'] ?? 0));
        $keep = Money::round((string) ($data['keep_credit'] ?? 0));
        if (Money::compare($apply, 0) < 0 || Money::compare($keep, 0) < 0 || Money::compare(Money::add($apply, $keep), $difference['owed']) > 0) {
            ReturnSettlementService::fail('Supplier credit allocation exceeds the remaining return credit.');
        }
        if (Money::compare(Money::add($apply, $keep), 0) > 0) {
            abort_unless($user->hasPermission('purchase_returns.apply_supplier_credit'), 403);
        }
        $otherDue = '0.00';
        foreach (Purchase::where('supplier_id', $purchase->supplier_id)->where('id', '!=', $purchase->id)->where('status', 'ACTIVE')->where('payment_tracking', true)->get() as $other) {
            $otherDue = Money::add($otherDue, $other->due_amount);
        }
        if (Money::compare($apply, $otherDue) > 0) {
            ReturnSettlementService::fail('Supplier credit exceeds other eligible bills from this supplier.');
        }
        $refund = Money::sub($difference['owed'], Money::add($apply, $keep));
        if (Money::compare($refund, 0) > 0) {
            abort_unless($user->hasPermission('purchase_returns.receive_refund'), 403);
        }
        if (($data['add_to_due'] ?? false) && Money::compare($difference['must_pay'], 0) > 0) {
            abort_unless($user->hasPermission('purchase_returns.apply_supplier_credit'), 403);
        }
        $result = $difference + ['amount' => $amount, 'cost_total' => Money::sum(array_column($lines, 'cost_total')), 'lines' => $lines, 'replacements' => $replacements, 'resolution' => $resolution, 'apply_due' => $apply, 'keep_credit' => $keep, 'refund_amount' => $refund, 'supplier_due_available' => $otherDue];
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

    public function complete(Purchase $original, array $data, User $user): PurchaseReturn
    {
        abort_unless($user->hasPermission('purchase_returns.create'), 403);

        return DB::transaction(function () use ($original, $data, $user) {
            $p = Purchase::whereKey($original->id)->lockForUpdate()->firstOrFail();
            Supplier::whereKey($p->supplier_id)->lockForUpdate()->firstOrFail();
            $existing = PurchaseReturn::where('token', $data['token'])->first();
            if ($existing) {
                abort_unless($existing->purchase_id === $p->id && $existing->user_id === $user->id, 403);

                if ($existing->status !== 'DRAFT') {
                    return $existing;
                }
            }
            Product::whereIn('id', $p->items()->pluck('product_id')->merge(array_column($data['replacements'] ?? [], 'product_id')))->orderBy('id')->lockForUpdate()->get();
            $q = $this->quote($p, $data, $user, true);
            if (! empty($data['quote_hash']) && ! hash_equals($q['quote_hash'], $data['quote_hash'])) {
                ReturnSettlementService::fail('Stock, balances or payment settings changed. Review the return again.');
            }
            $fields = ['purchase_id' => $p->id, 'supplier_id' => $p->supplier_id, 'user_id' => $user->id, 'token' => $data['token'], 'reference' => $existing?->reference ?? app(DocumentNumberService::class)->next('PURCHASE_RETURN', now()), 'reason' => $data['reason'] ?? '', 'notes' => $data['notes'] ?? null, 'amount' => $q['amount'], 'cost_total' => $q['cost_total'], 'due_reduction' => $q['due_reduction'], 'replacement_value' => $q['replacement_value'], 'refund_amount' => $q['refund_amount'], 'supplier_due_applied' => $q['apply_due'], 'supplier_credit' => $q['keep_credit'], 'resolution' => $q['resolution'], 'returned_at' => now(), 'approved_by' => $user->hasPermission('purchase_returns.approve') ? $user->id : null];
            $fields['status'] = 'COMPLETED';
            $fields['draft_payload'] = null;
            if ($existing) {
                $existing->update($fields);
                $r = $existing;
            } else {
                $r = PurchaseReturn::create($fields);
            }
            foreach ($q['lines'] as $line) {
                $item = $r->items()->create(array_diff_key($line, ['name' => true, 'unit' => true, 'allocations' => true]));
                $this->stock->applyPurchase($item, $line['allocations'], $r->reference, $user->id);
            }
            $unrecovered = Money::sub($q['cost_total'], $q['amount']);
            if (Money::compare($unrecovered, 0) > 0) {
                $cat = ExpenseCategory::firstOrCreate(['name' => 'Purchase Return Unrecovered Cost'], ['system' => true]);
                Expense::create(['expense_category_id' => $cat->id, 'user_id' => $user->id, 'type' => 'RETURN_WRITEOFF', 'expense_date' => today(), 'reference' => $r->reference, 'description' => 'Non-refundable landed cost · '.$p->reference, 'amount' => $unrecovered]);
            }
            if ($q['replacements']) {
                $replacement = app(PurchaseService::class)->save(['supplier_id' => $p->supplier_id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => array_map(fn ($line) => array_diff_key($line, ['total' => true]), $q['replacements'])], $user->id);
                $r->update(['replacement_purchase_id' => $replacement->id]);
                $credit = ReturnSettlementService::minimum($q['available_credit'], $q['replacement_value']);
                if (Money::compare($credit, 0) > 0) {
                    ReturnAccountAllocation::create(['purchase_return_id' => $r->id, 'purchase_id' => $replacement->id, 'supplier_id' => $p->supplier_id, 'kind' => 'REPLACEMENT_CREDIT', 'amount' => $credit]);
                }
                if (Money::compare($q['must_pay'], 0) > 0 && ! ($data['add_to_due'] ?? false)) {
                    $event = $this->settlement->money($r, 'purchase_return', 'PURCHASE_RETURN_PAYMENT', '-'.$q['must_pay'], $data['payment_method_id'] ?? null, $user);
                    // A non-cash allocation settles the invoice; the separate settlement records real cash movement.
                    ReturnAccountAllocation::create(['purchase_return_id' => $r->id, 'purchase_id' => $replacement->id, 'supplier_id' => $p->supplier_id, 'kind' => 'REPLACEMENT_PAYMENT', 'amount' => $q['must_pay']]);
                    $r->update(['additional_payment' => $q['must_pay'], 'register_id' => $event->register_id]);
                }
            }
            if (Money::compare($q['apply_due'], 0) > 0) {
                $this->settlement->applySupplier($r, 'purchase_return', $p->supplier_id, $q['apply_due'], $p->id, $user);
            }
            if (Money::compare($q['refund_amount'], 0) > 0) {
                $event = $this->settlement->money($r, 'purchase_return', 'PURCHASE_RETURN_REFUND', $q['refund_amount'], $data['payment_method_id'] ?? null, $user);
                $r->update(['register_id' => $event->register_id]);
            }
            Audit::record('purchase_return.complete', $r, [], $r->load('items.allocations', 'allocations', 'settlements')->toArray());

            return $r;
        }, 3);
    }

    public function cancel(PurchaseReturn $original, string $reason, User $user): void
    {
        abort_unless($user->hasPermission('purchase_returns.cancel'), 403);
        DB::transaction(function () use ($original, $reason, $user) {
            $r = PurchaseReturn::whereKey($original->id)->lockForUpdate()->firstOrFail();
            Purchase::whereKey($r->purchase_id)->lockForUpdate()->firstOrFail();
            Supplier::whereKey($r->supplier_id)->lockForUpdate()->firstOrFail();
            if ($r->status === 'DRAFT') {
                $r->update(['status' => 'CANCELLED', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
                Audit::record('return.draft_cancel', $r, [], ['reason' => $reason]);

                return;
            }
            if ($r->status !== 'COMPLETED') {
                ReturnSettlementService::fail('The return has already been cancelled.');
            }
            if ($r->replacement) {
                $replacement = Purchase::whereKey($r->replacement_purchase_id)->lockForUpdate()->firstOrFail();
                if ($replacement->returns()->exists() || $replacement->payments()->exists()) {
                    ReturnSettlementService::fail('Replacement purchase has subsequent financial activity.');
                }
                foreach ($replacement->items as $item) {
                    foreach ($item->stockLayers as $layer) {
                        $locked = ProductStockLayer::whereKey($layer->id)->lockForUpdate()->firstOrFail();
                        if (Money::compare($locked->remaining_quantity, $locked->original_quantity) !== 0 || $locked->movements()->where('quantity', '<', 0)->exists()) {
                            ReturnSettlementService::fail('Replacement stock has already been used.');
                        }
                        $product = Product::with('unit')->whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
                        $qty = '-'.$locked->remaining_quantity;
                        $cost = '-'.$locked->stock_value;
                        $locked->update(['remaining_quantity' => '0.000', 'remaining_cost_total' => $locked->remaining_cost_total !== null ? '0.00' : null, 'status' => 'VOID']);
                        app(StockLayerService::class)->movement($product, [[$locked, $qty, $cost]], 'REVERSE_REPLACEMENT', $r->reference, $user->id);
                    }
                }
                foreach ($replacement->items as $item) {
                    $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                    if (Money::compare($product->cost, $item->base_cost ?? $item->cost) === 0) {
                        $prior = PurchaseItem::where('product_id', $product->id)->where('purchase_id', '!=', $replacement->id)->whereHas('purchase', fn ($q) => $q->where('status', 'ACTIVE'))->latest('id')->first();
                        $cost = $prior?->base_cost ?? $prior?->cost ?? $item->previous_cost;
                        if ($cost !== null) {
                            $product->update(['cost' => $cost]);
                        }
                    }
                }
                $replacement->update(['status' => 'RETURN_CANCELLED']);
            }
            $this->stock->reverse($r, false, $user->id);
            $this->settlement->reverseMoney($r, 'purchase_return', $user);
            Expense::where('reference', $r->reference)->where('type', 'RETURN_WRITEOFF')->update(['status' => 'REVERSED']);
            $r->update(['status' => 'CANCELLED', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            Audit::record('purchase_return.cancel', $r, [], ['reason' => $reason]);
        }, 3);
    }
}
