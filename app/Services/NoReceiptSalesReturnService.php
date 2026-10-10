<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\ReturnAccountAllocation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NoReceiptSalesReturnService
{
    public function guard(User $user): void
    {
        abort_unless($user->hasPermission('sales_returns.create') && $user->hasPermission('sales_returns.no_receipt'), 403);
        if (! app(SettingsService::class)->get('no_receipt_enabled', true)) {
            ReturnSettlementService::fail('No-receipt sales returns are disabled.');
        }
    }

    public function suggestions(Product $product, ?int $unitId = null): array
    {
        $settings = app(SettingsService::class);
        $priceRule = $settings->get('no_receipt_credit_price', 'CURRENT_LOWEST');
        $prices = $product->stockLayers()->available();
        $basePrice = match ($priceRule) {
            'CURRENT_LOWEST' => (clone $prices)->min('selling_price'),
            'LATEST_PRICE' => $product->stockLayers()->where('status', 'ACTIVE')->latest('received_at')->latest('id')->value('selling_price'),
            default => $product->price,
        };
        $priceSource = $basePrice === null ? 'CURRENT_DEFAULT_FALLBACK' : $priceRule;
        $basePrice ??= $product->price;
        $option = app(ProductUnitService::class)->resolve($product, $unitId, '1', (string) $basePrice);
        $purchase = PurchaseItem::where('product_id', $product->id)->whereHas('purchase', fn ($q) => $q->where('status', 'ACTIVE'))->orderByDesc(Purchase::select('purchase_date')->whereColumn('purchases.id', 'purchase_items.purchase_id'))->latest('id')->first();
        $costRule = $settings->get('no_receipt_cost_method', 'LATEST_PURCHASE');
        $cost = $product->cost;
        $costSource = 'CURRENT_DEFAULT';
        $costReference = null;
        if ($costRule === 'LATEST_PURCHASE' && $purchase) {
            $layer = $purchase->stockLayers()->where('original_quantity', '>', 0)->latest('id')->first();
            $cost = $layer ? app(StockReturnService::class)->portion($layer->inventory_cost_total ?? Money::mul($layer->original_quantity, $layer->cost_price), '1', $layer->original_quantity) : ($purchase->base_cost ?? $purchase->cost);
            $costSource = 'LATEST_PURCHASE';
            $costReference = $purchase->purchase->reference;
        } elseif ($costRule === 'LATEST_PURCHASE') {
            $costSource = 'CURRENT_DEFAULT_FALLBACK';
        }
        if ($costRule === 'MANUAL') {
            $cost = null;
            $costSource = 'MANUAL';
        }

        return ['suggested_credit_price' => $priceRule === 'MANUAL' ? null : Money::round((string) $option['price']), 'credit_price_source' => $priceSource, 'cost_basis' => $cost, 'cost_basis_source' => $costSource, 'cost_basis_reference' => $costReference, 'stock_selling_price' => Money::round((string) $basePrice), 'unit_id' => $option['id'], 'unit' => $option['short_name']];
    }

    public function matches(Product $product, Customer $customer, User $user): array
    {
        $days = (int) app(SettingsService::class)->get('no_receipt_days_limit', 0);
        $rows = SaleItem::where('product_id', $product->id)->where('quantity', '>', SaleReturnItem::selectRaw('COALESCE(SUM(quantity),0)')->whereColumn('sale_item_id', 'sale_items.id')->whereHas('return', fn ($q) => $q->completed()))->whereHas('sale', fn ($q) => $q->visibleTo($user)->where('customer_id', $customer->id)->where('status', 'ACTIVE')->when($days, fn ($q) => $q->where('sold_at', '>=', today()->subDays($days))))->with('sale')->latest('id')->limit(20)->get();
        $matches = [];
        foreach ($rows as $item) {
            $line = collect(app(SaleAftercareService::class)->returnableLines($item->sale))->first(fn ($r) => $r['item']->id === $item->id);
            if (! $line || Money::compare($line['remaining'], 0) <= 0) {
                continue;
            }
            $matches[] = ['sale_item_id' => $item->id, 'invoice' => $item->sale->invoice, 'date' => $item->sale->sold_at->format('d M Y'), 'quantity' => $item->quantity, 'base_quantity' => $item->base_quantity ?? $item->quantity, 'remaining' => $line['remaining'], 'unit' => $item->unit, 'unit_id' => $item->unit_id, 'net_price' => app(StockReturnService::class)->portion($line['net'], '1', $item->quantity), 'original_price' => $item->price, 'allocated_discount' => Money::sub(Money::mul($item->price, $item->quantity), $line['net']), 'restock_prices' => $item->allocations()->with('layer')->orderByDesc('id')->get()->map(fn ($a) => ['stock_price' => $a->layer->selling_price, 'quantity' => Money::quantity($a->quantity, '-'.$a->returned_quantity), 'units' => array_map(fn ($u) => array_diff_key($u, ['cost' => true]), app(ProductUnitService::class)->options($product, $a->layer->selling_price))])->all()];
            if (count($matches) === 5) {
                break;
            }
        }
        $previous = SaleReturnItem::where('product_id', $product->id)->whereHas('return', fn ($q) => $q->visibleTo($user)->completed()->where('return_type', 'NO_RECEIPT')->where('customer_id', $customer->id)->where('returned_at', '>=', now()->subDays(30)))->with('return')->latest('id')->first();

        return ['matches' => $matches, 'warning' => $previous ? 'This customer returned '.$product->name.' without a receipt on '.$previous->return->returned_at->format('d M Y').' ('.$previous->return->reference.'). Please verify this return.' : null];
    }

    public function quote(array $data, User $user, bool $lock = false): array
    {
        $this->guard($user);
        $data += ['reason' => ''];
        $settings = app(SettingsService::class);
        $stock = app(StockReturnService::class);
        $settlement = app(ReturnSettlementService::class);
        $settlement->guard('sales', $data, $user, '0');
        $customer = empty($data['customer_id']) ? null : Customer::whereKey($data['customer_id'])->where('active', true)->firstOrFail();
        if (($settings->get('no_receipt_require_customer', false) || ! $settings->get('return_allow_anonymous', true)) && ! $customer) {
            ReturnSettlementService::fail('Select a customer for this no-receipt return.');
        }
        if (! empty($data['credit_customer_id']) && (int) $data['credit_customer_id'] !== $customer?->id) {
            ReturnSettlementService::fail('Select the customer at the start of this return before applying account credit.');
        }
        $lines = [];
        $groups = [];
        $seen = [];
        $days = (int) $settings->get('no_receipt_days_limit', 0);
        foreach ($data['items'] as $index => $input) {
            $qty = Money::quantity('0', (string) $input['quantity']);
            if (Money::compare($qty, 0) <= 0) {
                ReturnSettlementService::fail('Enter a positive return quantity.');
            }
            $product = Product::with('unit', 'conversions.unit')->whereKey($input['product_id'])->where('active', true)->firstOrFail();
            $action = $input['stock_action'] ?? $settings->get('return_default_stock_action', 'RESTOCK');
            if (! in_array($action, ['RESTOCK', 'WRITEOFF', 'SUPPLIER'], true)) {
                ReturnSettlementService::fail('Choose a valid stock action.');
            }
            if (($input['reason'] ?? '') === 'Other' && empty(trim($input['notes'] ?? ''))) {
                ReturnSettlementService::fail('Enter item notes for Other.');
            }
            if ($action === 'WRITEOFF') {
                abort_unless($user->hasPermission('sales_returns.writeoff'), 403);
            }
            if ($action === 'SUPPLIER') {
                abort_unless($user->hasPermission('sales_returns.return_to_supplier') && $user->hasPermission('sales_returns.no_receipt_supplier'), 403);
                if (! $settings->get('return_allow_supplier', true)) {
                    ReturnSettlementService::fail('Supplier returns are disabled.');
                }
            }
            $linked = ! empty($input['sale_item_id']);
            $key = $linked ? 'sale:'.$input['sale_item_id'] : 'product:'.$product->id.':'.($input['unit_id'] ?? $product->unit_id);
            if (isset($seen[$key])) {
                ReturnSettlementService::fail('Combine duplicate returned product rows before continuing.');
            }
            $seen[$key] = true;
            if ($linked) {
                $item = SaleItem::with('sale', 'product.unit')->findOrFail($input['sale_item_id']);
                if ($item->product_id !== $product->id || $item->sale->customer_id !== $customer?->id || (! empty($input['unit_id']) && (int) $input['unit_id'] !== $item->unit_id)) {
                    ReturnSettlementService::fail('The linked sale does not match the selected customer, product and unit.');
                }
                if ($days && $item->sale->sold_at->lt(today()->subDays($days))) {
                    ReturnSettlementService::fail('The linked purchase is outside the no-receipt return days limit.');
                }
                $groups[$item->sale_id]['sale'] = $item->sale;
                $groups[$item->sale_id]['inputs'][] = ['sale_item_id' => $item->id, 'quantity' => $qty, 'stock_action' => $action, 'supplier_id' => $input['supplier_id'] ?? null, 'reason' => $input['reason'] ?? $data['reason']];
                $groups[$item->sale_id]['indexes'][$item->id] = $index;

                continue;
            }
            if ($days) {
                if (empty($input['purchased_on'])) {
                    ReturnSettlementService::fail('Confirm the claimed purchase date for '.$product->name.'.');
                }
                $date = Carbon::parse($input['purchased_on']);
                if ($date->lt(today()->subDays($days)) || $date->gt(today())) {
                    ReturnSettlementService::fail('The claimed purchase date is outside the allowed return period.');
                }
            }
            $suggestion = $this->suggestions($product, $input['unit_id'] ?? null);
            $unit = app(ProductUnitService::class)->resolve($product, $input['unit_id'] ?? null, $qty);
            $base = $unit['base_stock_quantity'];
            $price = Money::round((string) ($input['credit_price'] ?? $suggestion['suggested_credit_price'] ?? '0'));
            if ($suggestion['suggested_credit_price'] === null && ! isset($input['credit_price'])) {
                ReturnSettlementService::fail('A manager must confirm the return credit price.');
            }
            if (Money::compare($price, 0) < 0) {
                ReturnSettlementService::fail('Return credit price cannot be negative.');
            }
            $overridden = $suggestion['suggested_credit_price'] === null || Money::compare($price, $suggestion['suggested_credit_price']) !== 0;
            if ($overridden) {
                abort_unless($user->hasPermission('sales_returns.no_receipt_price_override') || $user->hasPermission('sales_returns.override_credit_price'), 403);
                if ($suggestion['suggested_credit_price'] !== null && ! $settings->get('no_receipt_allow_price_change', true)) {
                    ReturnSettlementService::fail('Changing the suggested return credit price is disabled.');
                }
                if (empty(trim($input['price_override_reason'] ?? ''))) {
                    ReturnSettlementService::fail('Record a reason for the confirmed or changed return credit price.');
                }
            }
            $cost = $suggestion['cost_basis'];
            if ($cost === null) {
                abort_unless($user->hasPermission('sales_returns.no_receipt_approve') && $user->hasPermission('returns.view_cost'), 403);
                if (! isset($input['cost_basis']) || empty(trim($input['notes'] ?? ''))) {
                    ReturnSettlementService::fail('A manager must confirm the estimated primary-unit cost and enter notes.');
                }
                $cost = Money::round((string) $input['cost_basis']);
            } elseif (isset($input['cost_basis']) && Money::compare((string) $input['cost_basis'], $cost) !== 0) {
                ReturnSettlementService::fail('The configured estimated cost must be used.');
            }
            if (Money::compare($cost, 0) < 0) {
                ReturnSettlementService::fail('Estimated cost cannot be negative.');
            }
            $selling = Money::round((string) ($input['stock_selling_price'] ?? $suggestion['stock_selling_price']));
            $allowedPrices = $product->stockLayers()->available()->pluck('selling_price')->push($product->price)->push($suggestion['stock_selling_price']);
            if (! $allowedPrices->contains(fn ($p) => Money::compare((string) $p, $selling) === 0)) {
                ReturnSettlementService::fail('Choose a current selling-price group for the returned stock.');
            }
            $supplierId = null;
            if ($action === 'SUPPLIER') {
                $supplier = Supplier::whereKey($input['supplier_id'] ?? null)->where('active', true)->first();
                if (! $supplier) {
                    ReturnSettlementService::fail('Confirm the supplier for '.$product->name.'. No original supplier is known.');
                }
                $supplierId = $supplier->id;
            }
            $lines[$index] = ['sale_item_id' => null, 'product_id' => $product->id, 'unit_id' => $unit['id'], 'name' => $product->name, 'unit' => $unit['short_name'], 'quantity' => $qty, 'base_quantity' => $base, 'amount' => Money::mul($price, $qty), 'cost_total' => Money::mul($cost, $base), 'stock_action' => $action, 'reason' => $input['reason'] ?? $data['reason'], 'notes' => $input['notes'] ?? null, 'suggested_credit_price' => $suggestion['suggested_credit_price'], 'credit_price' => $price, 'credit_price_source' => $overridden ? 'MANUAL' : $suggestion['credit_price_source'], 'price_changed_by' => $overridden ? $user->id : null, 'price_override_reason' => $input['price_override_reason'] ?? null, 'cost_basis' => $cost, 'cost_basis_type' => 'ESTIMATED', 'cost_basis_source' => $suggestion['cost_basis_source'], 'cost_basis_reference' => $suggestion['cost_basis_reference'], 'stock_selling_price' => $selling, 'supplier_id' => $supplierId, 'purchased_on' => $input['purchased_on'] ?? null, 'fee_refund' => '0.00', 'allocations' => []];
        }
        $duePlan = [];
        $fee = '0.00';
        foreach ($groups as $saleId => $group) {
            $q = app(SalesReturnService::class)->quote($group['sale'], ['items' => $group['inputs'], 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null, 'resolution' => 'MONEY'], $user, $lock, true);
            $duePlan[] = ['sale_id' => $saleId, 'invoice' => $group['sale']->invoice, 'due_before' => $group['sale']->due_balance, 'amount' => $q['due_reduction'], 'due_after' => Money::sub($group['sale']->due_balance, $q['due_reduction'])];
            $fee = Money::add($fee, $q['fee_refund']);
            foreach ($q['lines'] as $n => $line) {
                $inputIndex = $group['indexes'][$line['sale_item_id']];
                $item = SaleItem::findOrFail($line['sale_item_id']);
                $lines[$inputIndex] = $line + ['product_id' => $item->product_id, 'unit_id' => $item->unit_id, 'suggested_credit_price' => $stock->portion($line['amount'], '1', $line['quantity']), 'credit_price' => $stock->portion($line['amount'], '1', $line['quantity']), 'credit_price_source' => 'ORIGINAL_SALE', 'cost_basis' => $stock->portion($line['cost_total'], '1', $line['base_quantity']), 'cost_basis_type' => 'HISTORICAL', 'cost_basis_source' => 'ORIGINAL_ALLOCATION', 'fee_refund' => $n === 0 ? $q['fee_refund'] : '0.00', 'original_invoice' => $group['sale']->invoice];
                $lines[$inputIndex]['notes'] = $data['items'][$inputIndex]['notes'] ?? null;
            }
        }
        ksort($lines);
        $lines = array_values($lines);
        if (! $lines) {
            ReturnSettlementService::fail('Add at least one returned product.');
        }
        $max = (string) $settings->get('no_receipt_max_quantity', '0');
        foreach ($lines as $line) {
            if (Money::compare($line['base_quantity'], 0) <= 0 || (Money::compare($max, 0) > 0 && Money::compare($line['base_quantity'], $max) > 0)) {
                ReturnSettlementService::fail('Check the returned quantity and configured no-receipt quantity limit.');
            }
        }
        $amount = Money::sum(array_column($lines, 'amount'));
        $verified = collect($lines)->where('cost_basis_type', 'HISTORICAL');
        $unverified = collect($lines)->where('cost_basis_type', 'ESTIMATED');
        $settlement->guard('sales', $data, $user, Money::add($amount, $fee));
        $threshold = (string) $settings->get('no_receipt_approval_above', '1000');
        if ($settings->get('no_receipt_require_approval', false) || (Money::compare($threshold, 0) > 0 && Money::compare(Money::add($amount, $fee), $threshold) > 0)) {
            abort_unless($user->hasPermission('sales_returns.no_receipt_approve'), 403, 'Manager approval is required for this no-receipt return.');
        }
        $resolution = $data['resolution'];
        $replacementQuote = null;
        $returnCredits = [];
        foreach ($verified as $line) {
            if ($line['stock_action'] === 'RESTOCK') {
                foreach ($line['allocations'] as $a) {
                    $id = $a['stock_layer_id'];
                    $returnCredits[$id] = ['quantity' => Money::quantity($returnCredits[$id]['quantity'] ?? '0', $a['quantity']), 'cost_total' => Money::add($returnCredits[$id]['cost_total'] ?? '0', $a['cost_total'])];
                }
            }
        }
        if ($resolution !== 'MONEY') {
            $inputs = $data['replacements'] ?? [];
            if (! $inputs) {
                ReturnSettlementService::fail('Select replacement products.');
            }
            $trusted = false;
            $roundingDiscount = '0.00';
            $used = [];
            if ($resolution === 'SAME') {
                foreach ($inputs as &$replacement) {
                    $index = $replacement['return_line_index'] ?? null;
                    if (! isset($lines[$index]) || isset($used[$index])) {
                        ReturnSettlementService::fail('Select one replacement for each returned product.');
                    }
                    $used[$index] = true;
                    $line = $lines[$index];
                    $replacement['product_id'] = $line['product_id'];
                    $replacement['unit_id'] = $line['unit_id'];
                    $replacement['quantity'] = $line['quantity'];
                    if ($line['sale_item_id']) {
                        $trusted = true;
                        $replacement['unit_price'] = (string) BigDecimal::of($line['amount'])->dividedBy($line['quantity'], 2, RoundingMode::CEILING);
                        $roundingDiscount = Money::add($roundingDiscount, Money::sub(Money::mul($replacement['unit_price'], $line['quantity']), $line['amount']));
                    }
                }
                unset($replacement);
                if (count($used) !== count($lines)) {
                    ReturnSettlementService::fail('Select replacements for all returned products.');
                }
            }
            $replacementQuote = app(SaleService::class)->quote(['items' => $inputs, 'payments' => [], 'discount' => $roundingDiscount], $user, $lock, null, $trusted, $returnCredits);
        } elseif (! empty($data['replacements'])) {
            ReturnSettlementService::fail('Money back cannot include replacement products.');
        }
        $originalDue = Money::sum(array_column($duePlan, 'amount'));
        $difference = $settlement->difference(Money::add($amount, $fee), $originalDue, $replacementQuote['sale_amount'] ?? '0.00');
        $apply = Money::round((string) ($data['apply_due'] ?? '0'));
        if (Money::compare($apply, 0) < 0 || Money::compare($apply, $difference['owed']) > 0) {
            ReturnSettlementService::fail('Due allocation exceeds available return credit.');
        }
        $allocations = [];
        $customerDue = '0.00';
        if ($customer) {
            $openingCredits = (string) ReturnAccountAllocation::where('customer_id', $customer->id)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount');
            $opening = Money::sub(Money::sub($customer->opening_due, $customer->opening_due_paid), $openingCredits);
            if (Money::compare($opening, 0) < 0) {
                $opening = '0.00';
            }
            $customerDue = $opening;
            $left = $apply;
            $take = ReturnSettlementService::minimum($left, $opening);
            if (Money::compare($take, 0) > 0) {
                $allocations[] = ['invoice' => 'Opening due', 'amount' => $take];
                $left = Money::sub($left, $take);
            }
            foreach ($customer->sales()->visibleTo($user)->where('status', 'ACTIVE')->oldest('sold_at')->orderBy('id')->get() as $sale) {
                $deduct = collect($duePlan)->firstWhere('sale_id', $sale->id)['amount'] ?? '0.00';
                $due = Money::sub($sale->due_balance, $deduct);
                $customerDue = Money::add($customerDue, $due);
                $take = ReturnSettlementService::minimum($left, $due);
                if (Money::compare($take, 0) > 0) {
                    $allocations[] = ['sale_id' => $sale->id, 'invoice' => $sale->invoice, 'amount' => $take];
                    $left = Money::sub($left, $take);
                }
            }
            if (Money::compare($apply, $customerDue) > 0) {
                ReturnSettlementService::fail('The customer has insufficient eligible outstanding due.');
            }
        } elseif (Money::compare($apply, 0) > 0 || ($data['add_to_due'] ?? false)) {
            ReturnSettlementService::fail('Select a customer before changing customer due.');
        }
        if (Money::compare($apply, 0) > 0) {
            abort_unless($user->hasPermission('sales_returns.apply_customer_due'), 403);
            if (! $settings->get('return_apply_customer_due', true)) {
                ReturnSettlementService::fail('Applying return credit to customer due is disabled.');
            }
        }
        if (($data['add_to_due'] ?? false) && Money::compare($difference['must_pay'], 0) > 0) {
            abort_unless($user->hasPermission('pos.due_sale'), 403);
        }
        $refund = Money::sub($difference['owed'], $apply);
        if (Money::compare($refund, 0) > 0) {
            abort_unless($user->hasPermission('sales_returns.refund'), 403);
            $method = PaymentMethod::whereKey($data['payment_method_id'] ?? null)->where('active', true)->first();
            if ($method?->type === 'CASH') {
                if (! $settings->get('no_receipt_cash_refund', false)) {
                    ReturnSettlementService::fail('Cash refunds without a receipt are disabled. Use an exchange, account credit, or an allowed noncash refund method.');
                }
                abort_unless($user->hasPermission('sales_returns.no_receipt_cash_refund'), 403);
            }
        }
        $q = $difference + ['amount' => $amount, 'fee_refund' => $fee, 'lines' => $lines, 'cost_total' => Money::sum(array_column($lines, 'cost_total')), 'verified_amount' => Money::sum($verified->pluck('amount')), 'unverified_amount' => Money::sum($unverified->pluck('amount')), 'historical_cost_total' => Money::sum($verified->pluck('cost_total')), 'estimated_cost_total' => Money::sum($unverified->pluck('cost_total')), 'verification_status' => $unverified->isEmpty() ? 'VERIFIED' : ($verified->isEmpty() ? 'UNVERIFIED' : 'PARTIALLY_VERIFIED'), 'replacement_quote' => $replacementQuote, 'resolution' => $resolution, 'refund_amount' => $refund, 'apply_due' => $apply, 'original_due_allocations' => $duePlan, 'due_allocations' => $allocations, 'customer_due_available' => $customerDue, 'customer_due_before' => Money::add($customerDue, $originalDue), 'customer_due_after' => Money::add(Money::sub($customerDue, $apply), ($data['add_to_due'] ?? false) ? $difference['must_pay'] : '0')];
        $hash = $q;
        unset($hash['replacement_quote']);
        $hash['replacement_snapshot'] = $replacementQuote;
        $hash['input'] = array_diff_key($data, ['token' => true, 'quote_hash' => true]);
        $hash['policy'] = $settings->all();
        $hash['method'] = PaymentMethod::find($data['payment_method_id'] ?? null)?->getAttributes();
        $q['quote_hash'] = hash_hmac('sha256', json_encode($hash), config('app.key'));

        return $q;
    }

    public function complete(array $data, User $user): SaleReturn
    {
        $this->guard($user);

        return DB::transaction(function () use ($data, $user) {
            $ids = SaleItem::whereIn('id', array_filter(array_column($data['items'], 'sale_item_id')))->pluck('sale_id')->unique()->sort();
            Sale::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if (! empty($data['customer_id'])) {
                Customer::whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();
            }
            Product::whereIn('id', array_merge(array_column($data['items'], 'product_id'), array_column($data['replacements'] ?? [], 'product_id')))->orderBy('id')->lockForUpdate()->get();
            $existing = SaleReturn::where('token', $data['token'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->return_type === 'NO_RECEIPT' && $existing->user_id === $user->id, 403);
                if ($existing->status !== 'DRAFT') {
                    return $existing;
                }
            }
            $q = $this->quote($data, $user, true);
            if (! empty($data['quote_hash']) && ! hash_equals($q['quote_hash'], $data['quote_hash'])) {
                ReturnSettlementService::fail('Stock, estimates, balances or policies changed. Review the return again.');
            }
            $fields = array_intersect_key($q, array_flip(['amount', 'fee_refund', 'cost_total', 'verified_amount', 'unverified_amount', 'historical_cost_total', 'estimated_cost_total', 'verification_status', 'due_reduction', 'refund_amount', 'replacement_value', 'resolution']));
            $approved = $user->hasPermission('sales_returns.no_receipt_approve');
            $fields += ['sale_id' => null, 'customer_id' => $data['customer_id'] ?? null, 'register_id' => app(RegisterService::class)->current($user->id, true)?->id, 'user_id' => $user->id, 'token' => $data['token'], 'reference' => $existing?->reference ?? app(DocumentNumberService::class)->next('SALES_RETURN', now()), 'return_type' => 'NO_RECEIPT', 'additional_payment' => '0.00', 'reason' => $data['reason'] ?? '', 'notes' => $data['notes'] ?? null, 'returned_at' => now(), 'status' => 'COMPLETED', 'draft_payload' => null, 'customer_due_applied' => $q['apply_due'], 'approved_by' => $approved ? $user->id : null, 'approved_at' => $approved ? now() : null];
            if ($existing) {
                $existing->update($fields);
                $r = $existing;
            } else {
                $r = SaleReturn::create($fields);
            }
            foreach ($q['lines'] as $line) {
                $values = array_diff_key($line, ['allocations' => true, 'original_invoice' => true]);
                $item = $r->items()->create($values);
                if ($item->sale_item_id) {
                    app(StockReturnService::class)->applySale($item, $line['allocations'], $item->stock_action, $r->reference, $user->id);
                } else {
                    $product = Product::with('unit')->findOrFail($item->product_id);
                    $layer = null;
                    if ($item->stock_action === 'RESTOCK') {
                        $layer = app(StockLayerService::class)->receive($product, $item->base_quantity, $item->cost_basis, $item->stock_selling_price, 'NO_RECEIPT_SALES_RETURN', $r->reference, $user->id, $item->id, null, null, $item->cost_total);
                    } else {
                        app(StockService::class)->move($product, '0.000', 'NO_RECEIPT_'.$item->stock_action, $r->reference, $user->id, false);
                    }
                    $item->allocations()->create(['stock_layer_id' => $layer?->id, 'quantity' => $item->base_quantity, 'cost_total' => $item->cost_total, 'stock_action' => $item->stock_action]);
                }
                if ($item->stock_action === 'WRITEOFF') {
                    $cat = ExpenseCategory::firstOrCreate(['name' => 'Sales Return Write-Off'], ['system' => true]);
                    Expense::create(['expense_category_id' => $cat->id, 'user_id' => $user->id, 'sale_id' => $item->item?->sale_id, 'type' => 'RETURN_WRITEOFF', 'expense_date' => today(), 'reference' => $r->reference, 'description' => 'No-receipt return · '.$item->name.' × '.$item->quantity.' · '.$item->cost_basis_type.' / '.$item->cost_basis_source.' · '.$item->reason, 'amount' => $item->cost_total]);
                }
                if ($item->stock_action === 'SUPPLIER') {
                    foreach ($item->allocations as $a) {
                        $supplierId = $item->sale_item_id ? collect($line['allocations'])->firstWhere('allocation_id', $a->sale_stock_allocation_id)['supplier_id'] : $item->supplier_id;
                        $claim = $r->supplierReturns()->where('supplier_id', $supplierId)->first();
                        $claim ??= SupplierReturn::create(['reference' => app(DocumentNumberService::class)->next('SUPPLIER_RETURN', now()), 'sale_return_id' => $r->id, 'supplier_id' => $supplierId, 'user_id' => $user->id, 'notes' => 'No-receipt sales return '.$r->reference.' · see item-level historical/estimated cost sources']);
                        $claim->update(['amount' => Money::add((string) ($claim->amount ?? '0'), $a->cost_total)]);
                        $a->update(['supplier_return_id' => $claim->id]);
                    }
                }
            }
            foreach ($q['original_due_allocations'] as $a) {
                if (Money::compare($a['amount'], 0) > 0) {
                    ReturnAccountAllocation::create(['sale_return_id' => $r->id, 'sale_id' => $a['sale_id'], 'customer_id' => $r->customer_id, 'kind' => 'ORIGINAL_DUE', 'amount' => $a['amount']]);
                }
            }
            app(SalesReturnService::class)->settleDocument($r, $q, $data, $user, $r->customer_id);
            Audit::record('sales_return.no_receipt_complete', $r, [], $r->load('items.allocations', 'allocations', 'settlements', 'supplierReturns')->toArray());

            return $r;
        }, 3);
    }

    public function cancel(SaleReturn $original, string $reason, User $user): void
    {
        abort_unless($user->hasPermission('sales_returns.cancel'), 403);
        DB::transaction(function () use ($original, $reason, $user) {
            $r = SaleReturn::visibleTo($user)->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($r->status === 'CANCELLED') {
                ReturnSettlementService::fail('This return is already cancelled.');
            }
            if ($r->status === 'COMPLETED') {
                Sale::whereIn('id', $r->items()->whereNotNull('sale_item_id')->get()->map(fn ($item) => $item->item->sale_id))->orderBy('id')->lockForUpdate()->get();
                if ($r->customer_id) {
                    Customer::whereKey($r->customer_id)->lockForUpdate()->firstOrFail();
                }
                if ($r->supplierReturns()->whereNotIn('status', ['PENDING', 'CANCELLED'])->exists()) {
                    ReturnSettlementService::fail('A linked supplier claim has already been sent or settled.');
                }
                if ($r->replacement) {
                    $exchange = Sale::whereKey($r->replacement_sale_id)->lockForUpdate()->firstOrFail();
                    if ($exchange->items()->whereHas('returns')->exists() || $exchange->collections()->exists()) {
                        ReturnSettlementService::fail('The exchange invoice has subsequent activity.');
                    }
                    foreach ($exchange->items as $item) {
                        $product = Product::with('unit')->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                        app(StockLayerService::class)->restore($product, $item, $item->base_quantity, 'REVERSE_EXCHANGE', $r->reference, $user->id);
                    }
                    $exchange->update(['status' => 'RETURN_CANCELLED', 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
                    Expense::where('sale_id', $exchange->id)->where('type', 'AUTOMATIC')->update(['status' => 'REVERSED']);
                }
                app(StockReturnService::class)->reverse($r, true, $user->id);
                app(ReturnSettlementService::class)->reverseMoney($r, 'sale_return', $user);
                Expense::where('reference', $r->reference)->where('type', 'RETURN_WRITEOFF')->update(['status' => 'REVERSED']);
                $r->supplierReturns()->update(['status' => 'CANCELLED']);
            }
            $r->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'cancelled_by' => $user->id, 'cancelled_at' => now()]);
            Audit::record('sales_return.no_receipt_cancel', $r, [], ['reason' => $reason]);
        }, 3);
    }
}
