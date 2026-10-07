<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Register;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\Resources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Store Admin', 'email' => 'admin@example.test', 'password' => 'a-secure-test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        app(RegisterService::class)->open($this->admin->id, '1000.00');
    }

    private function product(string $unit = 'pcs', string $price = '10000.00'): Product
    {
        return Product::create(['name' => 'Long supermarket product name for thermal receipts', 'sku' => Str::random(12), 'barcode' => Str::random(10), 'category_id' => Category::firstOrCreate(['name' => 'Groceries'])->id, 'unit_id' => Unit::where('short_name', $unit)->value('id'), 'cost' => '5000.00', 'price' => $price, 'stock' => '100.000', 'low_stock' => '5.000']);
    }

    private function rule(string $type = 'CARD', string $bearer = 'CUSTOMER', string $minimum = '0', string $operator = 'GTE', string $chargeType = 'PERCENTAGE', string $value = '3', int $priority = 10): PaymentChargeRule
    {
        return PaymentChargeRule::create(['payment_method_id' => PaymentMethod::where('type', $type)->value('id'), 'name' => 'Configured payment fee '.Str::random(5), 'minimum_amount' => $minimum, 'comparison_operator' => $operator, 'charge_type' => $chargeType, 'charge_value' => $value, 'charge_bearer' => $bearer, 'priority' => $priority, 'active' => true]);
    }

    private function data(Product $p, string $type = 'CARD', string $qty = '1'): array
    {
        return ['items' => [['product_id' => $p->id, 'quantity' => $qty]], 'discount' => '0', 'payment_method_id' => PaymentMethod::where('type', $type)->value('id')];
    }

    private function complete(array $data, ?string $paid = null): Sale
    {
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash'], 'amount_paid' => $paid ?? $quote['customer_payable']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_customer_card_fee_increases_receipt_and_never_creates_expense(): void
    {
        $this->rule();
        $sale = $this->complete($this->data($this->product()));
        $this->assertSame('300.00', $sale->processing_charge);
        $this->assertSame('10300.00', $sale->customer_payable);
        $this->assertDatabaseCount('expenses', 0);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Card Processing Fee')->assertSee('10,300.00');
        $this->assertSame('5000.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
    }

    public function test_business_card_fee_creates_expense_and_reduces_profit_once(): void
    {
        $this->rule(bearer: 'BUSINESS');
        $sale = $this->complete($this->data($this->product()));
        $this->assertSame('10000.00', $sale->customer_payable);
        $this->assertDatabaseHas('expenses', ['sale_id' => $sale->id, 'amount' => '300.00', 'type' => 'AUTOMATIC', 'status' => 'ACTIVE']);
        $profit = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('4700.00', $profit['net']);
        $this->assertSame('300.00', $profit['processing']);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('paid by merchant')->assertSee('10,000.00');
    }

    public function test_qr_below_threshold_has_no_fee(): void
    {
        $this->rule('QR', 'CUSTOMER', '5000', 'GT', 'PERCENTAGE', '10');
        $sale = $this->complete($this->data($this->product(price: '4500'), 'QR'));
        $this->assertSame('0.00', $sale->processing_charge);
    }

    public function test_qr_above_threshold_charges_ten_percent(): void
    {
        $this->rule('QR', 'CUSTOMER', '5000', 'GT', 'PERCENTAGE', '10');
        $sale = $this->complete($this->data($this->product(price: '6000'), 'QR'));
        $this->assertSame('600.00', $sale->processing_charge);
        $this->assertSame('6600.00', $sale->customer_payable);
    }

    public function test_qr_exact_threshold_with_strict_comparison_has_no_fee(): void
    {
        $this->rule('QR', 'CUSTOMER', '5000', 'GT', 'PERCENTAGE', '10');
        $sale = $this->complete($this->data($this->product(price: '5000'), 'QR'));
        $this->assertSame('0.00', $sale->processing_charge);
    }

    public function test_piece_unit_rejects_fractional_quantity(): void
    {
        $this->postJson(route('pos.quote'), $this->data($this->product(), 'CASH', '1.5'))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_weight_unit_accepts_fractional_quantity_and_updates_stock(): void
    {
        $sale = $this->complete($this->data($p = $this->product('kg', '800'), 'CASH', '1.250'));
        $this->assertSame('1000.00', $sale->sale_amount);
        $this->assertSame('98.750', $p->fresh()->stock);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('1.25');
    }

    public function test_cash_change_and_register_reconciliation_use_customer_payable(): void
    {
        $this->complete($this->data($this->product(price: '4350'), 'CASH'), '5000');
        $payment = Sale::first()->payment;
        $this->assertSame('650.00', $payment->change);
        $r = app(RegisterService::class)->current($this->admin->id);
        $this->assertSame('5350.00', app(RegisterService::class)->summary($r)['expected']);
        $this->post(route('register.close'), ['actual_cash' => '5300'])->assertRedirect();
        $this->assertSame('-50.00', $r->fresh()->difference);
    }

    public function test_rules_support_fixed_fees_and_priority(): void
    {
        $this->rule('QR', 'CUSTOMER', '0', 'GTE', 'PERCENTAGE', '1', 1);
        $this->rule('QR', 'CUSTOMER', '5000', 'GT', 'FIXED', '50', 10);
        $sale = $this->complete($this->data($this->product(price: '6000'), 'QR'));
        $this->assertSame('50.00', $sale->processing_charge);
    }

    public function test_server_ignores_browser_prices_and_totals(): void
    {
        $p = $this->product();
        $data = $this->data($p, 'CASH');
        $data['items'][0]['price'] = '1';
        $data['subtotal'] = '1';
        $data['customer_payable'] = '1';
        $data['processing_charge'] = '999';
        $sale = $this->complete($data);
        $this->assertSame('10000.00', $sale->sale_amount);
        $this->assertSame('0.00', $sale->processing_charge);
    }

    public function test_changed_rule_requires_review_and_saves_nothing(): void
    {
        $r = $this->rule();
        $p = $this->product();
        $data = $this->data($p);
        $quote = $this->postJson(route('pos.quote'), $data)->json();
        $r->update(['charge_value' => '4']);
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '10300', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('100.000', $p->fresh()->stock);
    }

    public function test_repeated_checkout_is_idempotent(): void
    {
        $p = $this->product();
        $data = $this->data($p, 'CASH');
        $quote = $this->postJson(route('pos.quote'), $data)->json();
        $data += ['amount_paid' => '10000', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']];
        $this->postJson(route('pos.complete'), $data)->assertOk();
        $this->postJson(route('pos.complete'), $data)->assertOk();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame('99.000', $p->fresh()->stock);
    }

    public function test_sale_void_restores_stock_reverses_fee_and_keeps_audit(): void
    {
        $this->rule(bearer: 'BUSINESS');
        $p = $this->product();
        $sale = $this->complete($this->data($p));
        $this->post(route('sales.void', $sale), ['reason' => 'Customer cancelled the order'])->assertRedirect();
        $this->assertSame('100.000', $p->fresh()->stock);
        $this->assertSame('VOIDED', $sale->fresh()->status);
        $this->assertDatabaseHas('expenses', ['sale_id' => $sale->id, 'status' => 'REVERSED']);
        $this->assertSame('0.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sale.void']);
        $this->postJson(route('sales.void', $sale), ['reason' => 'Repeat attempt'])->assertUnprocessable();
    }

    public function test_closed_register_prevents_sale_void(): void
    {
        $sale = $this->complete($this->data($this->product(), 'CASH'));
        $this->post(route('register.close'), ['actual_cash' => '11000'])->assertRedirect();
        $this->postJson(route('sales.void', $sale), ['reason' => 'Cannot change a closed shift'])->assertUnprocessable();
        $this->assertSame('ACTIVE', $sale->fresh()->status);
    }

    public function test_sale_rolls_back_all_writes_if_expense_creation_fails(): void
    {
        $this->rule(bearer: 'BUSINESS');
        $p = $this->product();
        $data = $this->data($p);
        $quote = app(SaleService::class)->quote($data, $this->admin);
        Expense::creating(fn () => throw new \RuntimeException('Simulated storage failure'));
        try {
            app(SaleService::class)->complete($data + ['amount_paid' => '10000', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']], $this->admin);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated storage failure', $e->getMessage());
        } finally {
            Expense::flushEventListeners();
        }$this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('100.000', $p->fresh()->stock);
        $this->assertSame(1, app(SettingsService::class)->get('next_invoice_number'));
    }

    public function test_purchase_increases_stock_and_can_be_edited_and_voided(): void
    {
        $p = $this->product('kg');
        $supplier = Supplier::create(['name' => 'Supplier']);
        $data = ['reference' => 'PO-001', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'quantity' => '1.250', 'cost' => '250']]];
        $this->post(route('purchases.store'), $data)->assertRedirect();
        $purchase = Purchase::first();
        $this->assertSame('101.250', $p->fresh()->stock);
        $this->assertSame('312.50', $purchase->total);
        $data['items'][0]['quantity'] = '2';
        $this->put(route('purchases.update', $purchase), $data)->assertRedirect();
        $this->assertSame('102.000', $p->fresh()->stock);
        $this->post(route('purchases.void', $purchase), ['reason' => 'Delivery returned'])->assertRedirect();
        $this->assertSame('100.000', $p->fresh()->stock);
    }

    public function test_cash_expense_affects_register_and_profit_and_reversal_is_audited(): void
    {
        $data = ['expense_category_id' => ExpenseCategory::where('name', 'General')->value('id'), 'expense_date' => today()->toDateString(), 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'description' => 'Store cleaning', 'amount' => '100'];
        $this->post(route('manage.store', 'expenses'), $data)->assertRedirect();
        $expense = Expense::first();
        $r = app(RegisterService::class)->current($this->admin->id);
        $this->assertSame('900.00', app(RegisterService::class)->summary($r)['expected']);
        $this->assertSame('-100.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->delete(route('manage.destroy', ['expenses', $expense->id]))->assertRedirect();
        $this->assertSame('REVERSED', $expense->fresh()->status);
        $this->assertSame('1000.00', app(RegisterService::class)->summary($r)['expected']);
    }

    public function test_cashier_cannot_access_or_change_settings_or_void_sales(): void
    {
        $cashier = User::create(['name' => 'Cashier', 'email' => 'cashier@example.test', 'password' => 'a-secure-test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->actingAs($cashier);
        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('settings.edit', 'pos'))->assertForbidden();
        $this->postJson(route('manage.store', 'payment-methods'), ['name' => 'Hack'])->assertForbidden();
        $this->get(route('reports.show', 'profit'))->assertForbidden();
    }

    public function test_inactive_payment_method_is_rejected(): void
    {
        $p = $this->product();
        $data = $this->data($p);
        PaymentMethod::find($data['payment_method_id'])->update(['active' => false]);
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
    }

    public function test_duplicate_cart_lines_are_rejected(): void
    {
        $data = $this->data($this->product());
        $data['items'][] = $data['items'][0];
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable();
    }

    public function test_overlapping_rules_at_same_priority_are_rejected(): void
    {
        $rule = $this->rule();
        $data = $rule->only('payment_method_id', 'minimum_amount', 'maximum_amount', 'comparison_operator', 'charge_type', 'charge_value', 'charge_bearer', 'priority', 'active');
        $data['name'] = 'Overlap';
        $this->postJson(route('manage.store', 'payment-rules'), $data)->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    public function test_insufficient_cash_payment_saves_nothing(): void
    {
        $data = $this->data($this->product(), 'CASH');
        $quote = $this->postJson(route('pos.quote'), $data)->json();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '1', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_discount_is_calculated_before_payment_charge(): void
    {
        $this->rule();
        $data = $this->data($this->product());
        $data['discount'] = '1000';
        $sale = $this->complete($data);
        $this->assertSame('9000.00', $sale->sale_amount);
        $this->assertSame('270.00', $sale->processing_charge);
        $this->assertSame('9270.00', $sale->customer_payable);
    }

    public function test_all_admin_screens_reports_and_exports_render_with_real_transactions(): void
    {
        $this->rule(bearer: 'BUSINESS');
        $sale = $this->complete($this->data($this->product()));
        $urls = [route('dashboard'), route('pos.index'), route('sales.index'), route('sales.show', $sale), route('sales.receipt', $sale), route('purchases.index'), route('purchases.create'), route('register.index'), route('register.show', $sale->register_id), route('settings.index'), route('reports.index'), route('profile')];
        foreach (Resources::all() as $key => $def) {
            $urls[] = route('manage.index', $key);
            $urls[] = route('manage.create', $key);
        }foreach (array_keys(config('pos')) as $group) {
            $urls[] = route('settings.edit', $group);
        }foreach (array_keys(ReportService::TITLES) as $report) {
            $urls[] = route('reports.show', $report);
            if ($report !== 'profit') {
                $this->get(route('reports.export', $report))->assertOk();
            }
        }foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_business_logo_upload_and_remove(): void
    {
        Storage::fake('public');
        $data = collect(config('pos.business'))->map(fn ($f) => $f[2])->all();
        $data['logo'] = UploadedFile::fake()->image('logo.png');
        $this->put(route('settings.update', 'business'), $data)->assertRedirect();
        $path = app(SettingsService::class)->get('logo');
        Storage::disk('public')->assertExists($path);
        unset($data['logo']);
        $data['remove_logo'] = true;
        $this->put(route('settings.update', 'business'), $data)->assertRedirect();
        $this->assertNull(app(SettingsService::class)->get('logo'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_product_unit_is_required_and_decimal_stock_is_validated(): void
    {
        $data = ['name' => 'Rice', 'sku' => 'RICE', 'category_id' => Category::firstOrCreate(['name' => 'Food'])->id, 'cost' => '10', 'price' => '20', 'stock' => '1.5', 'low_stock' => '5', 'active' => true];
        $this->postJson(route('manage.store', 'products'), $data)->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $data['unit_id'] = Unit::where('short_name', 'pcs')->value('id');
        $this->postJson(route('manage.store', 'products'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $data['unit_id'] = Unit::where('short_name', 'kg')->value('id');
        $this->postJson(route('manage.store', 'products'), $data)->assertRedirect();
        $this->assertDatabaseHas('products', ['sku' => 'RICE', 'stock' => '1.500']);
    }

    public function test_fee_percent_and_maximum_ranges_are_validated(): void
    {
        $data = ['name' => 'Invalid', 'payment_method_id' => PaymentMethod::where('type', 'CARD')->value('id'), 'minimum_amount' => '5000', 'maximum_amount' => '1000', 'comparison_operator' => 'GT', 'charge_type' => 'PERCENTAGE', 'charge_value' => '101', 'charge_bearer' => 'CUSTOMER', 'priority' => 10, 'active' => true];
        $this->postJson(route('manage.store', 'payment-rules'), $data)->assertUnprocessable()->assertJsonValidationErrors(['maximum_amount', 'charge_value']);
    }

    public function test_settings_and_units_persist_and_reseed_never_overwrites_them(): void
    {
        $data = collect(config('pos.pos'))->map(fn ($f) => $f[2])->all();
        $data['default_payment_method'] = PaymentMethod::where('code', 'CASH')->value('id');
        $data['invoice_prefix'] = 'SHOP-';
        $this->put(route('settings.update', 'pos'), $data)->assertRedirect();
        $this->post(route('manage.store', 'units'), ['name' => 'Tray', 'short_name' => 'tray', 'allow_decimal' => false, 'active' => true])->assertRedirect();
        $this->seed();
        $this->assertSame('SHOP-', app(SettingsService::class)->get('invoice_prefix'));
        $this->assertDatabaseHas('units', ['short_name' => 'tray']);
    }

    public function test_zero_percent_tiers_are_supported_but_fixed_zero_is_rejected(): void
    {
        $method = PaymentMethod::where('type', 'QR')->first();
        $data = ['payment_method_id' => $method->id, 'name' => 'No fee low tier', 'minimum_amount' => '0', 'maximum_amount' => '5000', 'comparison_operator' => 'GTE', 'charge_type' => 'PERCENTAGE', 'charge_value' => '0', 'charge_bearer' => 'CUSTOMER', 'priority' => 10, 'active' => true];
        $this->postJson(route('manage.store', 'payment-rules'), $data)->assertRedirect();
        $this->rule('QR', 'CUSTOMER', '5000', 'GT', 'PERCENTAGE', '1', 10);
        $sale = $this->complete($this->data($this->product(price: '5000'), 'QR'));
        $this->assertSame('0.00', $sale->processing_charge);
        $data['charge_type'] = 'FIXED';
        $data['name'] = 'Invalid fixed fee';
        $this->postJson(route('manage.store', 'payment-rules'), $data)->assertUnprocessable()->assertJsonValidationErrors('charge_value');
    }

    public function test_purchase_void_restores_the_prior_product_cost(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'Cost history supplier']);
        $data = ['reference' => 'COST-1', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'quantity' => '10', 'cost' => '6000']]];
        $purchase = app(PurchaseService::class)->save($data, $this->admin->id);
        $this->assertSame('6000.00', $p->fresh()->cost);
        $this->assertSame('5000.00', $purchase->items->first()->previous_cost);
        app(PurchaseService::class)->void($purchase, $this->admin->id, 'Supplier return');
        $this->assertSame('5000.00', $p->fresh()->cost);
    }

    public function test_historical_fractional_units_cannot_be_changed_to_whole_units(): void
    {
        $p = $this->product('kg', '800');
        $this->complete($this->data($p, 'CASH', '1.250'));
        $unit = $p->unit;
        $data = $unit->only('name', 'short_name', 'allow_decimal', 'active');
        $data['allow_decimal'] = false;
        $this->putJson(route('manage.update', ['units', $unit->id]), $data)->assertUnprocessable()->assertJsonValidationErrors('allow_decimal');
    }

    public function test_ids_must_be_scalar_integers_and_names_must_be_strings(): void
    {
        $data = $this->data($this->product());
        $data['payment_method_id'] = [$data['payment_method_id']];
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable()->assertJsonValidationErrors('payment_method_id');
        $this->postJson(route('manage.store', 'categories'), ['name' => ['Invalid array']])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson(route('manage.index', ['resource' => 'products', 'q' => ['invalid']]))->assertUnprocessable();
    }

    public function test_money_values_and_percentage_rules_enforce_their_numeric_limits(): void
    {
        $data = ['name' => 'Out of range', 'code' => 'LIMIT', 'type' => 'CUSTOM', 'charge_bearer' => 'CUSTOMER', 'display_order' => '1000000000', 'active' => true];
        $this->postJson(route('manage.store', 'payment-methods'), $data)->assertUnprocessable()->assertJsonValidationErrors('display_order');
        $data = ['payment_method_id' => PaymentMethod::where('type', 'CARD')->value('id'), 'name' => 'Too much', 'minimum_amount' => '0', 'maximum_amount' => null, 'comparison_operator' => 'GTE', 'charge_type' => 'PERCENTAGE', 'charge_value' => '101', 'charge_bearer' => 'CUSTOMER', 'priority' => 0, 'active' => true];
        $this->postJson(route('manage.store', 'payment-rules'), $data)->assertUnprocessable()->assertJsonValidationErrors('charge_value');
    }

    public function test_decimal_precision_setting_is_enforced_on_the_server(): void
    {
        app(SettingsService::class)->put('system', ['quantity_decimals' => 2]);
        $this->postJson(route('pos.quote'), $this->data($this->product('kg'), 'CASH', '1.251'))->assertUnprocessable();
    }

    public function test_stock_settings_require_explicit_zero_and_negative_stock_configuration(): void
    {
        $p = $this->product();
        $p->update(['stock' => '0']);
        $data = $this->data($p, 'CASH');
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable();
        app(SettingsService::class)->put('stock', ['sell_zero_stock' => true, 'negative_stock' => false]);
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable();
        app(SettingsService::class)->put('stock', ['negative_stock' => true]);
        $this->complete($data);
        $this->assertSame('-1.000', $p->fresh()->stock);
    }

    public function test_custom_payment_methods_are_usable_and_reported(): void
    {
        $method = PaymentMethod::create(['name' => 'Store voucher', 'code' => 'VOUCHER', 'type' => 'CUSTOM', 'charge_bearer' => 'CUSTOMER', 'active' => true]);
        $data = $this->data($this->product());
        $data['payment_method_id'] = $method->id;
        $sale = $this->complete($data);
        $this->assertSame('Store voucher', $sale->payment->method_name);
        $this->get(route('reports.show', 'payments'))->assertOk()->assertSee('Store voucher');
    }

    public function test_payment_snapshots_survive_configuration_changes(): void
    {
        $r = $this->rule();
        $sale = $this->complete($this->data($this->product()));
        $r->update(['charge_value' => '8', 'charge_bearer' => 'BUSINESS']);
        $sale->payment->method->update(['name' => 'Renamed card', 'type' => 'CUSTOM']);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Card Processing Fee')->assertSee('10,300.00');
        $this->assertSame('300.00', $sale->fresh()->processing_charge);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_csv_export_runs_and_neutralizes_spreadsheet_formulas(): void
    {
        $p = $this->product();
        $p->update(['name' => '=HYPERLINK("https://example.test")']);
        $response = $this->get(route('reports.export', 'stock'))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->complete($this->data($p, 'CASH'));
        $this->assertStringContainsString('Cash', $this->get(route('reports.export', 'payments'))->streamedContent());
    }

    public function test_register_aggregate_reports_reconcile_to_the_closed_shift(): void
    {
        $this->complete($this->data($this->product(price: '4350'), 'CASH'), '5000');
        $this->post(route('register.movement'), ['type' => 'IN', 'amount' => '200', 'description' => 'Drawer top-up'])->assertRedirect();
        $this->post(route('register.movement'), ['type' => 'OUT', 'amount' => '100', 'description' => 'Safe deposit'])->assertRedirect();
        $this->post(route('register.close'), ['actual_cash' => '5450'])->assertRedirect();
        $this->get(route('reports.show', 'register'))->assertOk()->assertSee('5,450.00');
        $this->assertSame('0.00', Register::first()->difference);
    }

    public function test_inactive_accounts_cannot_keep_using_their_existing_session(): void
    {
        $this->admin->update(['active' => false]);
        $this->getJson(route('pos.products'))->assertUnauthorized();
    }

    public function test_audit_records_never_include_passwords_or_session_tokens(): void
    {
        $user = User::create(['name' => 'Disposable user', 'email' => 'delete@example.test', 'password' => 'a-secure-test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->delete(route('manage.destroy', ['users', $user->id]))->assertRedirect();
        $audit = AuditLog::where('action', 'users.delete')->firstOrFail();
        $this->assertArrayNotHasKey('password', $audit->before);
        $this->assertArrayNotHasKey('remember_token', $audit->before);
    }

    public function test_large_receipts_preserve_long_names_decimal_items_discount_and_logo(): void
    {
        $data = ['items' => [], 'discount' => '100', 'payment_method_id' => PaymentMethod::where('type', 'CARD')->value('id')];
        for ($i = 0; $i < 30; $i++) {
            $p = $this->product($i % 2 ? 'kg' : 'pcs', '200');
            $p->update(['name' => 'Premium supermarket product with a very long name and pack description '.$i]);
            $data['items'][] = ['product_id' => $p->id, 'quantity' => $i % 2 ? '1.250' : '1'];
        }
        $this->rule();
        app(SettingsService::class)->put('business', ['logo' => 'branding/receipt-test.png', 'address' => 'A long store address with a city and country', 'phone' => 'Store telephone']);
        $sale = $this->complete($data);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('branding/receipt-test.png')->assertSee('description 29')->assertSee('1.25')->assertSee('Discount')->assertSee('@page{size:80mm 297mm', false);
    }

    public function test_cashier_discount_limit_and_used_record_deletion_are_enforced(): void
    {
        $p = $this->product();
        $cashier = User::create(['name' => 'Limit Cashier', 'email' => 'limit@example.test', 'password' => 'a-secure-test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->actingAs($cashier);
        $data = $this->data($p, 'CASH');
        $data['discount'] = '1001';
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable()->assertJsonValidationErrors('discount');
        $this->session(['password_hash_web' => $this->admin->getAuthPassword()]);
        $this->actingAs($this->admin);
        $sale = $this->complete($this->data($p, 'CASH'));
        $this->delete(route('manage.destroy', ['products', $p->id]))->assertRedirect()->assertSessionHasErrors('delete');
        $this->assertNotNull($p->fresh());
        $this->delete(route('manage.destroy', ['payment-methods', $sale->payment->payment_method_id]))->assertRedirect()->assertSessionHasErrors('delete');
    }

    public function test_reseeding_preserves_renamed_units_and_payment_methods(): void
    {
        $unit = Unit::where('short_name', 'pcs')->first();
        $unit->update(['short_name' => 'piece']);
        $card = PaymentMethod::where('type', 'CARD')->first();
        $card->update(['code' => 'VISA', 'name' => 'Visa terminal']);
        $this->seed();
        $this->assertSame('piece', $unit->fresh()->short_name);
        $this->assertSame('VISA', $card->fresh()->code);
        $this->assertSame(1, PaymentMethod::where('type', 'CARD')->count());
    }

    public function test_money_formatting_preserves_large_values_and_pennies(): void
    {
        $this->assertSame('9,999,999,999,999.99', Money::display('9999999999999.99'));
        $this->assertSame('-100.01', Money::display('-100.01'));
        $this->assertSame('0.50', Money::display(0.5));
        app(SettingsService::class)->put('system', ['number_decimals' => 0]);
        $this->assertSame('49.50', Money::display('49.50'));
    }

    public function test_profile_password_change_validates_current_password_and_retains_own_session(): void
    {
        $this->putJson(route('profile.update'), ['current_password' => 'wrong', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertUnprocessable();
        $this->put(route('profile.update'), ['current_password' => 'a-secure-test-password', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertRedirect();
        $this->assertTrue(Hash::check('new-secure-password', $this->admin->fresh()->password));
        $this->get(route('profile'))->assertOk();
    }

    public function test_invalid_logo_type_and_oversize_uploads_are_rejected(): void
    {
        $data = collect(config('pos.business'))->map(fn ($f) => $f[2])->all();
        $data['logo'] = UploadedFile::fake()->create('logo.php', 1, 'application/x-php');
        $this->putJson(route('settings.update', 'business'), $data)->assertUnprocessable()->assertJsonValidationErrors('logo');
        $data['logo'] = UploadedFile::fake()->image('big.png')->size(3000);
        $this->putJson(route('settings.update', 'business'), $data)->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_payment_rules_can_inherit_the_method_bearer_or_override_it(): void
    {
        $rule = $this->rule();
        $rule->update(['charge_bearer' => null]);
        $method = $rule->method;
        $method->update(['charge_bearer' => 'BUSINESS']);
        $sale = $this->complete($this->data($this->product()));
        $this->assertSame('10000.00', $sale->customer_payable);
        $this->assertDatabaseHas('expenses', ['sale_id' => $sale->id, 'amount' => '300.00']);
        $rule->update(['charge_bearer' => 'CUSTOMER']);
        $sale = $this->complete($this->data($this->product()));
        $this->assertSame('10300.00', $sale->customer_payable);
        $this->assertNull($sale->payment->expense);
    }

    public function test_search_mode_and_scanner_settings_are_applied(): void
    {
        $p = $this->product();
        $category = Category::firstOrCreate(['name' => 'Other category']);
        app(SettingsService::class)->put('pos', ['search_mode' => 'name']);
        $this->getJson(route('pos.products', ['q' => $p->barcode]))->assertOk()->assertJsonCount(0);
        $this->getJson(route('pos.products', ['q' => $p->barcode, 'scan' => 1, 'category_id' => $category->id]))->assertOk()->assertJsonPath('0.id', $p->id);
        app(SettingsService::class)->put('pos', ['barcode_enabled' => false]);
        $this->getJson(route('pos.products', ['q' => $p->barcode, 'scan' => 1]))->assertOk()->assertJsonCount(0);
    }
}
