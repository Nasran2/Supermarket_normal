<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private SettingsService $settings, private PaymentChargeService $charges, private StockService $stock, private RegisterService $registers) {}

    public function quote(array $data, User $user, bool $lock = false): array
    {
        $ids = array_column($data['items'], 'product_id');
        $products = Product::with('unit')->whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $products->lockForUpdate();
        }
        $products = $products->get()->keyBy('id');
        if ($lock) {
            $units = Unit::whereIn('id', $products->pluck('unit_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($products as $product) {
                $product->setRelation('unit', $units[$product->unit_id]);
            }
        }
        $lines = [];
        $subtotal = '0.00';
        $cost = '0.00';
        foreach ($data['items'] as $item) {
            $p = $products->get($item['product_id']);
            if (! $p || ! $p->active || ! $p->unit->active) {
                throw ValidationException::withMessages(['items' => 'One or more products or units are inactive.']);
            }
            $q = (string) $item['quantity'];
            $this->stock->validateQuantity($p, $q);
            if (Money::compare($p->stock, 0) <= 0 && ! $this->settings->get('sell_zero_stock', false)) {
                throw ValidationException::withMessages(['items' => $p->name.' is out of stock.']);
            }
            if (Money::compare($q, $p->stock) > 0 && ! $this->settings->get('negative_stock', false)) {
                throw ValidationException::withMessages(['items' => 'Insufficient stock for '.$p->name.'. Available: '.$p->stock.' '.$p->unit->short_name]);
            }
            $total = Money::mul($p->price, $q);
            $subtotal = Money::add($subtotal, $total);
            $cost = Money::add($cost, Money::mul($p->cost, $q));
            $lines[] = ['product_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit->short_name, 'quantity' => Money::quantity('0', $q), 'price' => $p->price, 'cost' => $p->cost, 'total' => $total];
        }
        $discount = Money::round((string) ($data['discount'] ?? 0));
        if (Money::compare($discount, 0) > 0) {
            if (! $this->settings->get('allow_discount', true)) {
                throw ValidationException::withMessages(['discount' => 'Discounts are disabled.']);
            }
            $limit = $user->hasPermission('settings.pos') ? '100' : (string) $this->settings->get('max_discount_percent', 10);
            if (Money::compare($discount, Money::percent($subtotal, $limit)) > 0) {
                throw ValidationException::withMessages(['discount' => 'Discount exceeds your allowed '.$limit.'%.']);
            }
        }
        if (Money::compare($discount, $subtotal) > 0) {
            throw ValidationException::withMessages(['discount' => 'Discount cannot exceed subtotal.']);
        }
        $amount = Money::sub($subtotal, $discount);
        $methodQuery = PaymentMethod::whereKey($data['payment_method_id'])->where('active', true);
        if ($lock) {
            $methodQuery->lockForUpdate();
        }
        $method = $methodQuery->first();
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => 'Select an active payment method.']);
        }
        $charge = $this->charges->calculateCharge($method, $amount, $lock);
        foreach ([$subtotal, $cost, $charge['customer_payable'], $charge['processing_charge']] as $total) {
            if (Money::compare($total, '9999999999999.99') > 0) {
                throw ValidationException::withMessages(['items' => 'The order total exceeds the supported range.']);
            }
        }
        $quote = ['items' => $lines, 'subtotal' => $subtotal, 'discount' => $discount, 'sale_amount' => $amount, 'cost_total' => $cost, 'payment_method_id' => $method->id, 'method_name' => $method->name, 'method_type' => $method->type] + $charge;
        $quote['quote_hash'] = hash_hmac('sha256', json_encode($quote), config('app.key'));

        return $quote;
    }

    public function complete(array $data, User $user): Sale
    {
        return DB::transaction(function () use ($data, $user) {
            $register = $this->registers->current($user->id, true);
            if (! $register) {
                throw ValidationException::withMessages(['register' => 'Open your register before selling.']);
            }
            $existing = Sale::where('checkout_token', $data['checkout_token'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id, 403);

                return $existing;
            }
            $quote = $this->quote($data, $user, true);
            if (! hash_equals($quote['quote_hash'], $data['quote_hash'])) {
                throw ValidationException::withMessages(['payment' => 'The order or payment rule changed. Review payment again.']);
            }
            $paid = Money::round((string) $data['amount_paid']);
            if (Money::compare($paid, $quote['customer_payable']) < 0) {
                throw ValidationException::withMessages(['amount_paid' => 'Payment is less than the amount to pay.']);
            }
            if ($quote['method_type'] !== 'CASH' && Money::compare($paid, $quote['customer_payable']) !== 0) {
                throw ValidationException::withMessages(['amount_paid' => 'Non-cash payments must match the amount to pay.']);
            }
            $sequence = Setting::where('key', 'next_invoice_number')->lockForUpdate()->firstOrFail();
            $number = (int) json_decode($sequence->value, true);
            $prefix = $this->settings->get('invoice_prefix', 'INV-');
            $invoice = $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            while (Sale::where('invoice', $invoice)->exists()) {
                $invoice = $prefix.str_pad((string) ++$number, 6, '0', STR_PAD_LEFT);
            }
            $sequence->update(['value' => json_encode($number + 1)]);
            DB::afterCommit(fn () => Cache::forget('business_settings'));
            $sale = Sale::create(['invoice' => $invoice, 'checkout_token' => $data['checkout_token'], 'user_id' => $user->id, 'register_id' => $register->id, 'customer_id' => $data['customer_id'] ?? null, 'sold_at' => now(), 'subtotal' => $quote['subtotal'], 'discount' => $quote['discount'], 'sale_amount' => $quote['sale_amount'], 'processing_charge' => $quote['processing_charge'], 'customer_payable' => $quote['customer_payable'], 'cost_total' => $quote['cost_total'], 'notes' => $data['notes'] ?? null]);
            foreach ($quote['items'] as $line) {
                $sale->items()->create($line);
                $p = Product::findOrFail($line['product_id']);
                $this->stock->move($p, '-'.$line['quantity'], 'SALE', $invoice, $user->id);
            }
            $payment = $sale->payment()->create(['payment_method_id' => $quote['payment_method_id'], 'payment_charge_rule_id' => $quote['rule_id'], 'method_name' => $quote['method_name'], 'method_type' => $quote['method_type'], 'rule_name' => $quote['rule_name'], 'charge_type' => $quote['charge_type'], 'charge_value' => $quote['charge_value'], 'charge_bearer' => $quote['charge_bearer'], 'sale_amount' => $quote['sale_amount'], 'processing_charge' => $quote['processing_charge'], 'customer_payable' => $quote['customer_payable'], 'amount_paid' => $paid, 'change' => $quote['method_type'] === 'CASH' ? Money::sub($paid, $quote['customer_payable']) : '0.00', 'reference' => $data['reference'] ?? null]);
            $this->charges->createProcessingExpense($payment);
            Audit::record('sale.complete', $sale, [], $quote);

            return $sale;
        }, 3);
    }

    public function void(Sale $sale, string $reason, int $userId): void
    {
        DB::transaction(function () use ($sale, $reason, $userId) {
            $r = Register::whereKey($sale->register_id)->lockForUpdate()->firstOrFail();
            if ($r->closed_at) {
                throw ValidationException::withMessages(['reason' => 'This register is closed. Completed register records cannot be changed.']);
            }
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['reason' => 'This sale has already been voided.']);
            }
            $items = $sale->items()->orderBy('product_id')->get();
            foreach ($items as $item) {
                $p = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                $this->stock->move($p, $item->quantity, 'SALE VOID', $sale->invoice, $userId);
            }
            Expense::where('sale_id', $sale->id)->where('type', 'AUTOMATIC')->update(['status' => 'REVERSED']);
            $sale->update(['status' => 'VOIDED', 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);
            Audit::record('sale.void', $sale, [], ['reason' => $reason]);
        }, 3);
    }
}
