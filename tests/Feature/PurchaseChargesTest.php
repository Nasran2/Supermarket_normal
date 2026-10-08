<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseChargesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Purchase QA', 'username' => 'purchase-qa', 'email' => 'purchase-qa@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Supplier QA', 'active' => true]);
        $this->product = Product::create(['name' => 'Soap QA', 'sku' => 'SOAP-PAY-QA', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '0', 'low_stock' => '1', 'active' => true]);
        app(RegisterService::class)->open($this->admin->id, '5000');
    }

    private function method(string $type): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['reference' => 'PUR-'.Str::random(8), 'supplier_id' => $this->supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $this->product->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '130']]], $extra);
    }

    private function purchase(array $extra = []): Purchase
    {
        $this->post(route('purchases.store'), $this->data($extra))->assertRedirect()->assertSessionHasNoErrors();

        return Purchase::latest('id')->firstOrFail();
    }

    private function payment(string $amount, string $type = 'CASH'): array
    {
        return ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $this->method($type), 'reference' => 'Supplier receipt QA'];
    }

    public function test_amount_automatically_sets_partial_paid_and_change(): void
    {
        $p = $this->purchase(['amount_paid' => '400', 'payment_method_id' => $this->method('CASH')]);
        $this->assertSame('Partial', $p->payment_status);
        $this->assertSame('600.00', $p->due_amount);
        $p = $this->purchase(['amount_paid' => '1250', 'payment_method_id' => $this->method('CASH')]);
        $this->assertSame('Paid', $p->payment_status);
        $this->assertSame('1000.00', $p->paid_amount);
        $this->assertSame('0.00', $p->due_amount);
        $this->assertSame('1250.00', $p->payments->first()->amount_paid);
        $this->assertSame('250.00', $p->payments->first()->change);
        $this->assertSame('1000.00', $p->payments->first()->movement->amount);
        $this->get(route('purchases.show', $p))->assertOk()->assertSee('change 250.00');
        $this->assertSame('3600.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
    }

    public function test_later_payment_with_excess_records_change_once(): void
    {
        $p = $this->purchase();
        $data = $this->payment('1200');
        $this->post(route('purchases.payments.store', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('purchases.payments.store', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_payments', 1);
        $this->assertSame('1000.00', $p->fresh()->paid_amount);
        $this->assertSame('200.00', $p->payments->first()->change);
        $this->assertSame('4000.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
    }

    private function charges(): array
    {
        return [['label' => 'Shipping', 'amount' => '50'], ['label' => 'Handling', 'amount' => '10']];
    }

    public function test_multiple_charges_default_to_expenses_without_inflating_product_cost(): void
    {
        $p = $this->purchase(['charges' => $this->charges(), 'amount_paid' => '0']);
        $this->assertSame('1000.00', $p->subtotal);
        $this->assertSame('60.00', $p->charges_total);
        $this->assertSame('1060.00', $p->total);
        $this->assertSame('1060.00', $p->due_amount);
        $this->assertSame('Unpaid', $p->payment_status);
        $this->assertDatabaseCount('purchase_charges', 2);
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseCount('register_movements', 0);
        $this->assertSame('100.00', $this->product->fresh()->cost);
        $this->assertNull($p->items->first()->stockLayers->first()->inventory_cost_total);
        $profit = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('60.00', $profit['purchaseCharges']);
        $this->assertSame('-60.00', $profit['net']);
        $this->get(route('reports.show', 'expenses'))->assertOk()->assertSee('Shipping');
        $this->get(route('reports.show', 'profit'))->assertOk()->assertSee('Purchase shipping');
        $expense = Expense::first();
        $this->putJson(route('manage.update', ['expenses', $expense]), ['description' => 'Changed'])->assertUnprocessable();
    }

    private function sell(Product $product, string $qty): Sale
    {
        $data = ['items' => [['product_id' => $product->id, 'quantity' => $qty]], 'payment_method_id' => $this->method('CASH')];
        $quote = app(SaleService::class)->quote($data, $this->admin);

        return app(SaleService::class)->complete($data + ['amount_paid' => $quote['customer_payable'], 'quote_hash' => $quote['quote_hash'], 'checkout_token' => (string) Str::uuid()], $this->admin);
    }

    public function test_allocated_charges_preserve_exact_inventory_and_sale_costs(): void
    {
        $other = Product::create(['name' => 'Second', 'sku' => 'SECOND-COST', 'unit_id' => $this->product->unit_id, 'cost' => '100', 'price' => '130', 'stock' => '0', 'active' => true]);
        $p = $this->purchase(['charge_treatment' => 'COST', 'charges' => [['label' => 'Freight', 'amount' => '10.01']], 'items' => [['product_id' => $this->product->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '130'], ['product_id' => $other->id, 'quantity' => '5', 'cost' => '100', 'selling_price' => '130']]]);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('1510.01', $p->total);
        $this->assertSame(['6.67', '3.34'], $p->items->pluck('allocated_charge')->all());
        $layers = ProductStockLayer::orderBy('id')->get();
        $this->assertSame(['1006.67', '503.34'], $layers->pluck('inventory_cost_total')->all());
        $this->assertSame(['1006.67', '503.34'], $layers->map(fn ($l) => $l->stock_value)->all());
        $this->get(route('reports.show', ['stock', 'stock_view' => 'layers']))->assertOk()->assertSee('1,006.67')->assertSee('503.34');
        $one = $this->sell($this->product, '1');
        $rest = $this->sell($this->product, '9');
        $two = $this->sell($other, '5');
        $this->assertSame('1510.01', Money::sum([$one->cost_total, $rest->cost_total, $two->cost_total]));
        $profit = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('1510.01', $profit['cogs']);
        $this->assertSame('439.99', $profit['net']);
        $this->assertSame('0.00', $layers->first()->fresh()->stock_value);
    }

    public function test_returns_and_adjustment_reversal_preserve_landed_cost_cents(): void
    {
        $p=$this->purchase(['charge_treatment'=>'COST','charges'=>[['label'=>'Rounding freight','amount'=>'0.01']],'items'=>[['product_id'=>$this->product->id,'quantity'=>'3','cost'=>'100','selling_price'=>'130']]]);
        $layer=$p->items->first()->stockLayers->first();
        app(\App\Services\StockLayerService::class)->adjust($this->product->fresh(),'-1',$layer->id,'100','130','LANDED-ADJUST','STOCK ADJUSTMENT',$this->admin->id);
        $this->assertSame('200.01',$layer->fresh()->stock_value);
        app(\App\Services\StockLayerService::class)->reverseSource($this->product->fresh(),'LANDED-ADJUST','REVERSE STOCK ADJUSTMENT',$this->admin->id);
        $this->assertSame('300.01',$layer->fresh()->stock_value);
        $first=$this->sell($this->product,'1');
        $second=$this->sell($this->product,'1');
        $third=$this->sell($this->product,'1');
        $this->assertSame('100.01',$second->cost_total);
        $this->post(route('sales.returns.store',$second),['token'=>(string)Str::uuid(),'reason'=>'Return landed-cost stock','payment_method_id'=>$this->method('CASH'),'items'=>[['sale_item_id'=>$second->items->first()->id,'quantity'=>'1']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('100.01',$layer->fresh()->stock_value);
        $resold=$this->sell($this->product,'1');
        $this->assertSame('100.01',$resold->cost_total);
        $this->assertSame('300.01',app(ProfitLossService::class)->calculate(today()->toDateString(),today()->toDateString())['cogs']);
        $this->assertSame('0.00',$layer->fresh()->stock_value);
    }

    public function test_edit_and_void_reverse_automatic_charge_expenses(): void
    {
        $p = $this->purchase(['charges' => $this->charges()]);
        $data = $this->data(['reference' => $p->reference, 'charges' => [['label' => 'Revised freight', 'amount' => '20']]]);
        $this->put(route('purchases.update', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1020.00', $p->fresh()->total);
        $this->assertSame(2, Expense::where('status', 'REVERSED')->count());
        $this->assertSame(1, Expense::where('status', 'ACTIVE')->count());
        $this->assertSame(1, $p->fresh()->charges->count());
        $this->post(route('purchases.void', $p), ['reason' => 'Unused delivery cancellation'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, Expense::where('status', 'ACTIVE')->count());
        $this->assertSame('0.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['expenses']);
    }

    public function test_invalid_charges_roll_back_stock_and_payment(): void
    {
        foreach ([['label' => 'Bad', 'amount' => '-1'], ['label' => '', 'amount' => '10']] as $charge) {
            $this->postJson(route('purchases.store'), $this->data(['charges' => [$charge], 'amount_paid' => '100', 'payment_method_id' => $this->method('CASH')]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('0.000', $this->product->fresh()->stock);
    }

    public function test_purchase_monthly_and_sale_daily_numbers_preserve_existing_documents(): void
    {
        $first = $this->purchase(['auto_reference' => true, 'reference' => 'Ignore preview']);
        $second = $this->purchase(['auto_reference' => true, 'reference' => $first->reference]);
        $this->assertSame('pur-'.today()->format('Ym').'-0001', $first->reference);
        $this->assertSame('pur-'.today()->format('Ym').'-0002', $second->reference);
        $prior = today()->subMonth()->startOfMonth();
        $backdated = $this->purchase(['auto_reference' => true, 'purchase_date' => $prior->toDateString()]);
        $this->assertSame('pur-'.$prior->format('Ym').'-0001', $backdated->reference);
        $one = $this->sell($this->product, '1');
        $two = $this->sell($this->product, '1');
        $this->assertSame('INV-'.today()->format('Ymd').'-00001', $one->invoice);
        $this->assertSame('INV-'.today()->format('Ymd').'-00002', $two->invoice);
        $this->travel(1)->days();
        $three = $this->sell($this->product,'1');
        $this->assertSame('INV-'.today()->format('Ymd').'-00001',$three->invoice);
        $this->assertSame($one->invoice,$one->fresh()->invoice);
    }
}
