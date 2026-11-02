<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TaxInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SaleAftercareService;
use App\Services\SaleRevisionService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerTaxInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['features.customer_tax' => true]);
        $this->cashier = User::create(['name' => 'Tax cashier', 'username' => 'tax-cashier', 'email' => 'tax@example.test', 'password' => 'password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '0');
        $this->product = Product::create(['name' => 'Tax test groceries', 'sku' => 'TAX-QA', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '118', 'cost' => '40', 'stock' => '50', 'active' => true]);
        app(SettingsService::class)->put('business', ['business_name' => 'Test Store', 'legal_name' => 'Test Store Ltd', 'tax_number' => '123456789', 'address' => '10 Test Street', 'phone' => '0112345678']);
    }

    private function complete(?Customer $customer = null, array $extra = []): array
    {
        $data = $extra + ['items' => [['product_id' => $this->product->id, 'quantity' => '2']], 'customer_id' => $customer?->id, 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $result = $this->postJson(route('pos.complete'), $data + ['amount_paid' => $quote['customer_payable'], 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk()->json();

        return [Sale::latest('id')->firstOrFail(), $result];
    }

    private function details(array $extra = []): array
    {
        return $extra + ['name' => 'Purchaser Ltd', 'tax_number' => '987654321', 'phone' => '0771234567', 'address' => '20 Customer Street', 'delivery_date' => today()->toDateString(), 'place_of_supply' => '10 Test Street'];
    }

    public function test_disabled_feature_hides_all_new_controls_and_blocks_direct_endpoints(): void
    {
        [$sale] = $this->complete();
        config(['features.customer_tax' => false]);
        $this->get(route('pos.index'))->assertOk()->assertDontSee('id="bill-type-dialog"', false)->assertDontSee('name="tax_number"', false)->assertDontSee('id="cart-vat"', false);
        $this->getJson(route('sales.tax.context', $sale))->assertNotFound();
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertNotFound();
        $this->get(route('sales.tax.download', $sale))->assertNotFound();
        $this->get(route('sales.tax.preview', $sale))->assertNotFound();
        $this->flushSession();
        $this->actingAs(User::where('username', 'admin')->first());
        $this->get(route('settings.index'))->assertDontSee('Tax settings');
        $this->get(route('settings.edit', 'customer_tax'))->assertNotFound();
        $this->putJson(route('settings.update', 'customer_tax'), ['vat_rate' => 5, 'vat_price_mode' => 'EXCLUSIVE'])->assertNotFound();
        $this->get(route('manage.create', 'customers'))->assertDontSee('name="tax_number"', false);
        $this->flushSession();
        $this->actingAs($this->cashier);
        [$plain, $result] = $this->complete();
        $this->assertNull($plain->tax);
        $this->assertNull($result['tax_context_url']);
        $this->assertSame('236.00', $plain->sale_amount);
    }

    public function test_inclusive_vat_matches_paid_total_and_customer_is_only_requested_for_pdf(): void
    {
        [$sale, $result] = $this->complete();
        $this->assertNull($sale->customer_id);
        $this->assertSame('236.00', $sale->customer_payable);
        $this->assertSame('200.00', $sale->tax['base']);
        $this->assertSame('36.00', $sale->tax['vat']);
        $this->assertNotNull($result['tax_context_url']);
        $this->getJson($result['tax_context_url'])->assertOk()->assertJsonPath('customer', null)->assertJsonPath('can_create', true);
        $this->postJson(route('sales.tax.store', $sale), [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'tax_number', 'phone', 'address']);
        $this->assertDatabaseCount('customers', 0);
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertOk();
        $this->assertNotNull($sale->fresh()->customer_id);
        $this->assertDatabaseHas('customers', ['name' => 'Purchaser Ltd', 'tax_number' => '987654321']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 1);
        $pdf = $this->get(route('sales.tax.download', $sale))->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->get(route('sales.tax.preview', $sale))->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertSee('data-tax-invoice', false)->assertSee('Purchaser Ltd')->assertSee('Download PDF')->assertSee('Print invoice');
        $this->get(route('sales.tax.preview', $sale).'?embed=1')->assertOk()->assertDontSee('class="print-controls"', false);
    }

    public function test_existing_customers_missing_tax_details_are_completed_without_overwriting_saved_contacts(): void
    {
        $customer = Customer::create(['name' => 'Existing buyer', 'phone' => '0770000000', 'active' => true]);
        [$sale] = $this->complete($customer);
        $this->getJson(route('sales.tax.context', $sale))->assertOk()->assertJsonPath('customer.tax_number', null)->assertJsonPath('fixed_customer', true);
        $data = $this->details(['customer_id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone]);
        $this->postJson(route('sales.tax.store', $sale), array_replace($data, ['phone' => 'overwrite']))->assertForbidden();
        unset($data['place_of_supply']);
        $this->postJson(route('sales.tax.store', $sale), $data)->assertOk();
        $this->assertSame($data['address'], TaxInvoice::where('sale_id', $sale->id)->first()->snapshot['delivery']['place_of_supply']);
        $this->getJson(route('sales.tax.context', $sale))->assertOk()->assertJsonPath('place_of_supply', $data['address']);
        $this->assertSame('987654321', $customer->fresh()->tax_number);
        $this->assertSame('0770000000', $customer->fresh()->phone);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_settings_change_rate_and_exclusive_vat_is_collected_before_payment_after_discounts(): void
    {
        $admin = User::where('username', 'admin')->first();
        $this->flushSession();
        $this->actingAs($admin);
        $this->put(route('settings.update', 'customer_tax'), ['vat_rate' => '10', 'vat_price_mode' => 'EXCLUSIVE'])->assertRedirect();
        $this->putJson(route('settings.update', 'customer_tax'), ['vat_rate' => '-1', 'vat_price_mode' => 'bad'])->assertUnprocessable();
        $this->flushSession();
        $this->actingAs($this->cashier);
        [$sale] = $this->complete(null, ['discount' => '6']);
        $this->assertSame('230.00', $sale->tax['base']);
        $this->assertSame('23.00', $sale->tax['vat']);
        $this->assertSame('253.00', $sale->customer_payable);
        $this->assertSame('253.00', $sale->collected_total);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('VAT (10%)')->assertSee('253.00');
        $net = app(SaleAftercareService::class)->returnableLines($sale);
        $this->assertSame('253.00', Money::sum(array_column($net, 'net')));
    }

    public function test_issued_invoice_is_idempotent_and_keeps_snapshot_after_customer_and_setting_changes(): void
    {
        [$sale] = $this->complete();
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertOk();
        $before = TaxInvoice::firstOrFail()->snapshot;
        $sale->fresh()->customer->update(['name' => 'Changed name']);
        app(SettingsService::class)->put('customer_tax', ['vat_rate' => 25, 'vat_price_mode' => 'EXCLUSIVE']);
        $this->postJson(route('sales.tax.store', $sale), $this->details(['name' => 'Duplicate']))->assertOk();
        $this->assertDatabaseCount('tax_invoices', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame($before, TaxInvoice::first()->snapshot);
        $this->assertSame('236.00', $sale->fresh()->customer_payable);
        $this->expectException(ValidationException::class);
        app(SaleRevisionService::class)->assertEditable($sale, $this->cashier->id);
    }

    public function test_vat_rate_and_price_mode_are_preserved_when_an_existing_sale_is_revised(): void
    {
        [$sale] = $this->complete();
        app(SettingsService::class)->put('customer_tax', ['vat_rate' => '10', 'vat_price_mode' => 'EXCLUSIVE']);
        $this->flushSession();
        $this->actingAs(User::where('username', 'admin')->first());
        $data = ['items' => [['product_id' => $this->product->id, 'quantity' => '3', 'unit_price' => '118']],
            'payments' => [['payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount' => '354']],
            'version' => app(SaleRevisionService::class)->version($sale)];
        $quote = $this->postJson(route('sales.edit.quote', $sale), $data)->assertOk()->assertJsonPath('tax.mode', 'INCLUSIVE')->assertJsonPath('tax.rate', '18')->json();
        $data['payments'][0]['amount_paid'] = $quote['customer_payable'];
        $this->postJson(route('sales.revise', $sale), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $this->assertSame('354.00', $sale->fresh()->customer_payable);
        $this->assertSame('54.00', $sale->fresh()->tax['vat']);
    }

    public function test_pdf_line_allocations_reconcile_discounts_and_zero_value_sales(): void
    {
        [$sale] = $this->complete(null, ['items' => [
            ['product_id' => $this->product->id, 'quantity' => '1', 'discount_type' => 'AMOUNT', 'discount_value' => '1'],
            ['product_id' => $this->product->id, 'quantity' => '2'],
        ], 'discount' => '0.01']);
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertOk();
        $snapshot = TaxInvoice::where('sale_id', $sale->id)->first()->snapshot;
        $this->assertSame($sale->tax['base'], Money::sum(array_column($snapshot['lines'], 'amount')));
        $this->assertSame($sale->customer_payable, Money::add($snapshot['tax']['base'], $snapshot['tax']['vat']));
        app(SettingsService::class)->put('pos', ['max_discount_percent' => 100]);
        [$free] = $this->complete(null, ['items' => [['product_id' => $this->product->id, 'quantity' => '1', 'discount_type' => 'PERCENT', 'discount_value' => '100', 'use_default_discount' => false]]]);
        $this->assertSame('0.00', $free->tax['base']);
        $this->assertSame('0.00', $free->tax['vat']);
    }

    public function test_an_authorized_user_can_complete_shop_setup_and_download_without_leaving_checkout(): void
    {
        [$sale] = $this->complete();
        app(SettingsService::class)->put('business', ['tax_number' => '', 'address' => '', 'phone' => '']);
        $this->flushSession();
        $this->actingAs(User::where('username', 'admin')->first());
        $this->getJson(route('sales.tax.context', $sale))->assertOk()->assertJsonPath('can_configure_supplier', true)->assertJsonPath('supplier.tax_number', '');
        $supplier = ['business_name' => 'Configured Store', 'tax_number' => '111222333', 'address' => '10 Store Road', 'phone' => '0111111111'];
        $this->postJson(route('sales.tax.store', $sale), $this->details(['supplier' => $supplier]))->assertOk();
        $this->assertSame($supplier['tax_number'], app(SettingsService::class)->get('tax_number'));
        $snapshot = TaxInvoice::where('sale_id', $sale->id)->first()->snapshot;
        $this->assertSame($supplier['tax_number'], $snapshot['supplier']['tax_number']);
        $this->assertSame('987654321', $snapshot['purchaser']['tax_number']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.business']);
        $this->get(route('sales.tax.download', $sale))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_cashier_cannot_change_shop_settings_through_a_tax_invoice(): void
    {
        [$sale] = $this->complete();
        $this->getJson(route('sales.tax.context', $sale))->assertOk()->assertJsonPath('can_configure_supplier', false);
        $supplier = ['business_name' => 'Unauthorized Store', 'tax_number' => '111222333', 'address' => '10 Store Road', 'phone' => '0111111111'];
        $this->postJson(route('sales.tax.store', $sale), $this->details(['supplier' => $supplier]))->assertForbidden();
        $this->assertSame('123456789', app(SettingsService::class)->get('tax_number'));
        $this->assertDatabaseCount('tax_invoices', 0);
    }

    public function test_failed_issuance_rolls_back_inline_shop_changes_and_clears_cached_values(): void
    {
        $customer = Customer::create(['name' => 'Original buyer', 'active' => true]);
        $other = Customer::create(['name' => 'Other buyer', 'active' => true]);
        [$sale] = $this->complete($customer);
        $this->flushSession();
        $this->actingAs(User::where('username', 'admin')->first());
        $supplier = ['business_name' => 'Changed Store', 'tax_number' => '111222333', 'address' => '10 Store Road', 'phone' => '0111111111'];
        $this->postJson(route('sales.tax.store', $sale), $this->details(['customer_id' => $other->id, 'supplier' => $supplier]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertSame('123456789', app(SettingsService::class)->get('tax_number'));
        $this->assertDatabaseCount('tax_invoices', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.business']);
    }

    public function test_tax_settings_update_shared_shop_details_and_freeze_invoice_note(): void
    {
        $this->flushSession();
        $this->actingAs(User::where('username', 'admin')->first());
        $this->get(route('settings.edit', 'customer_tax'))->assertOk()->assertSee('Shop TIN / VAT number')->assertSee('Tax invoice note');
        $this->put(route('settings.update', 'customer_tax'), ['business_name' => 'Edited Tax Store', 'tax_number' => '111222333', 'address' => 'New Store Address', 'phone' => '0117777777', 'vat_rate' => '8', 'vat_price_mode' => 'INCLUSIVE', 'tax_invoice_note' => 'Saved invoice note'])->assertRedirect();
        $this->assertDatabaseHas('settings', ['key' => 'tax_number', 'group' => 'business', 'value' => json_encode('111222333')]);
        $this->assertSame('Edited Tax Store', app(SettingsService::class)->get('business_name'));
        $this->flushSession();
        $this->actingAs($this->cashier);
        [$sale] = $this->complete();
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertOk()->assertJsonPath('preview_url', route('sales.tax.preview', $sale));
        app(SettingsService::class)->put('customer_tax', ['tax_invoice_note' => 'Later note']);
        $this->get(route('sales.tax.preview', $sale))->assertOk()->assertSee('Saved invoice note')->assertDontSee('Later note');
        $this->assertSame('8', $sale->tax['rate']);
    }

    public function test_supplier_details_and_sale_ownership_are_enforced(): void
    {
        [$sale] = $this->complete();
        app(SettingsService::class)->put('business', ['tax_number' => '']);
        $this->getJson(route('sales.tax.context', $sale))->assertOk()->assertJsonPath('missing_supplier.0', 'TIN / VAT number');
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertUnprocessable()->assertJsonValidationErrors('supplier');
        $other = User::create(['name' => 'Other cashier', 'username' => 'other-tax', 'email' => 'other-tax@example.test', 'password' => 'password', 'role_id' => $this->cashier->role_id]);
        $this->flushSession();
        $this->actingAs($other);
        $this->getJson(route('sales.tax.context', $sale))->assertForbidden();
        $this->postJson(route('sales.tax.store', $sale), $this->details())->assertForbidden();
        $this->get(route('sales.tax.download', $sale))->assertForbidden();
        $this->get(route('sales.tax.preview', $sale))->assertForbidden();
    }
}
