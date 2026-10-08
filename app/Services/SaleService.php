<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
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
    public function __construct(private SettingsService $settings, private SplitPaymentService $payments, private StockService $stock, private RegisterService $registers) {}

    public function quote(array $data, User $user, bool $lock = false, ?Sale $editing = null): array
    {
        $ids = array_column($data['items'], 'product_id');
        $products = Product::with(['unit', 'conversions.unit'])->whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $products->lockForUpdate();
        }
        $products = $products->get()->keyBy('id');
        if ($lock) {
            $units = Unit::whereIn('id', $products->pluck('unit_id')->merge($products->flatMap(fn ($p) => $p->conversions->pluck('unit_id')))->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($products as $product) {
                $product->setRelation('unit', $units[$product->unit_id]);
                foreach ($product->conversions as $conversion) {
                    $conversion->setRelation('unit', $units[$conversion->unit_id]);
                }
            }
        }
        $lines = [];
        $subtotal = '0.00';
        $cost = '0.00';
        $lineDiscounts = '0.00';
        $discountBudget = '0.00';
        $referenceSubtotal = '0.00';
        foreach ($data['items'] as $item) {
            $p = $products->get($item['product_id']);
            if (! $p || ! $p->active || ! $p->unit->active) {
                throw ValidationException::withMessages(['items' => 'One or more products or units are inactive.']);
            }
            $q = (string) $item['quantity'];
            $selected = app(ProductUnitService::class)->resolve($p, $item['unit_id'] ?? null, $q);
            $baseQuantity = $selected['base_stock_quantity'];
            $available = $p->stock;
            if ($editing) {
                foreach ($editing->items->where('product_id', $p->id) as $original) {
                    $available = Money::quantity($available, $original->base_quantity ?? $original->quantity);
                }
            }
            if (Money::compare($available, 0) <= 0 && ! $this->settings->get('sell_zero_stock', false)) {
                throw ValidationException::withMessages(['items' => $p->name.' is out of stock.']);
            }
            if (Money::compare($baseQuantity, $available) > 0 && ! $this->settings->get('negative_stock', false)) {
                throw ValidationException::withMessages(['items' => 'Insufficient stock for '.$p->name.'. Available: '.$available.' '.$p->unit->short_name]);
            }
            $adjustment = app(SaleLineService::class)->calculate($item, $selected['price']);
            $catalogTotal = Money::mul($selected['price'], $q);
            $priceReduction = Money::sub($catalogTotal, $adjustment['line_subtotal']);
            $discountBudget = Money::add($discountBudget, Money::add(Money::compare($priceReduction, 0) > 0 ? $priceReduction : '0', $adjustment['line_discount']));
            $referenceSubtotal = Money::add($referenceSubtotal, Money::compare($catalogTotal, $adjustment['line_subtotal']) > 0 ? $catalogTotal : $adjustment['line_subtotal']);
            $lineDiscounts = Money::add($lineDiscounts, $adjustment['line_discount']);
            $subtotal = Money::add($subtotal, $adjustment['line_subtotal']);
            $cost = Money::add($cost, Money::mul($p->cost, $baseQuantity));
            $lines[] = ['product_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $selected['short_name'], 'unit_id' => $selected['id'], 'base_quantity' => $baseQuantity, 'base_cost' => $p->cost, 'quantity' => Money::quantity('0', $q), 'cost' => $selected['cost']] + $adjustment;
        }
        $invoiceDiscount = app(SaleLineService::class)->billDiscount($data, Money::sub($subtotal, $lineDiscounts));
        $discount = Money::add($lineDiscounts, $invoiceDiscount);
        $discountBudget = Money::add($discountBudget, $invoiceDiscount);
        if (Money::compare($discountBudget, 0) > 0) {
            if (! $this->settings->get('allow_discount', true)) {
                throw ValidationException::withMessages(['discount' => 'Discounts and price reductions are disabled.']);
            }
            $limit = $user->hasPermission('settings.pos') ? '100' : (string) $this->settings->get('max_discount_percent', 10);
            if (Money::compare($discountBudget, Money::percent($referenceSubtotal, $limit)) > 0) {
                throw ValidationException::withMessages(['discount' => 'Discounts and price reductions exceed your allowed '.$limit.'%.']);
            }
        }
        if (Money::compare($discount, $subtotal) > 0) {
            throw ValidationException::withMessages(['discount' => 'Discount cannot exceed subtotal.']);
        }
        $amount = Money::sub($subtotal, $discount);
        $charge = $this->payments->quote($data, $amount, $lock);
        foreach ([$subtotal, $cost, $charge['customer_payable'], $charge['processing_charge']] as $total) {
            if (Money::compare($total, '9999999999999.99') > 0) {
                throw ValidationException::withMessages(['items' => 'The order total exceeds the supported range.']);
            }
        }
        $quote = ['items' => $lines, 'subtotal' => $subtotal, 'discount' => $discount, 'line_discounts' => $lineDiscounts, 'invoice_discount' => $invoiceDiscount, 'sale_amount' => $amount, 'cost_total' => $cost] + $charge;
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
            if (! empty($data['allow_due'])) {
                $customer = Customer::whereKey($data['customer_id'] ?? null)->where('active', true)->lockForUpdate()->first();
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Select or add an active customer before completing a sale with due.']);
                }
            }
            $quote = $this->quote($data, $user, true);
            if (! hash_equals($quote['quote_hash'], $data['quote_hash'])) {
                throw ValidationException::withMessages(['payment' => 'The order or payment rule changed. Review payment again.']);
            }
            $payments = $this->payments->settle($data, $quote);
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
                $this->stock->move($p, '-'.$line['base_quantity'], 'SALE', $invoice, $user->id);
            }
            foreach ($payments as $part) {
                $payment = $sale->payments()->create([
                    'payment_method_id' => $part['payment_method_id'], 'payment_charge_rule_id' => $part['rule_id'],
                    'method_name' => $part['method_name'], 'method_type' => $part['method_type'], 'rule_name' => $part['rule_name'],
                    'charge_type' => $part['charge_type'], 'charge_value' => $part['charge_value'], 'charge_bearer' => $part['charge_bearer'],
                    'sale_amount' => $part['sale_amount'], 'processing_charge' => $part['processing_charge'],
                    'customer_payable' => $part['customer_payable'], 'amount_paid' => $part['amount_paid'],
                    'change' => $part['change'], 'reference' => $part['reference'],
                ]);
                app(PaymentChargeService::class)->createProcessingExpense($payment);
            }
            Audit::record('sale.complete', $sale, [], $quote);

            return $sale->fresh();
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
            if ($sale->returns()->exists() || $sale->collections()->exists()) {
                throw ValidationException::withMessages(['reason' => 'This invoice has returns or due collections. Use item returns for corrections; it cannot be deleted or voided again.']);
            }
            $items = $sale->items()->orderBy('product_id')->get();
            foreach ($items as $item) {
                $p = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                $this->stock->move($p, $item->base_quantity ?? $item->quantity, 'SALE VOID', $sale->invoice, $userId);
            }
            Expense::where('sale_id', $sale->id)->where('type', 'AUTOMATIC')->update(['status' => 'REVERSED']);
            $sale->update(['status' => 'VOIDED', 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);
            Audit::record('sale.void', $sale, [], ['reason' => $reason]);
        }, 3);
    }
}
