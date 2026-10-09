<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductMovementHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Stock history QA', 'username' => 'movement', 'email' => 'movement@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000');
        $this->product = Product::create(['name' => 'History groceries', 'sku' => 'HIST1', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '20']);
        $data = ['items' => [['product_id' => $this->product->id, 'quantity' => '3']], 'payments' => [['payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount' => '300', 'amount_paid' => '300']]];
        $quote = app(SaleService::class)->quote($data, $this->cashier);
        $this->sale = app(SaleService::class)->complete($data + ['quote_hash' => $quote['quote_hash'], 'checkout_token' => (string) Str::uuid()], $this->cashier);
    }

    public function test_product_history_lists_sale_movement_balance_cashier_and_invoice_link(): void
    {
        $url = route('sales.show', $this->sale);
        $this->get(route('manage.show', ['products', $this->product->id]))->assertOk()->assertSee('Stock movement history')->assertSee('SALE')->assertSee($this->sale->invoice)->assertSee('href="'.$url.'"', false)->assertSee('Stock history QA')->assertSee('Stock after');
        $this->get(route('stock.edit', $this->product))->assertOk()->assertSee('href="'.$url.'"', false);
    }

    public function test_return_movement_links_to_original_invoice(): void
    {
        $this->post(route('sales.returns.store', $this->sale), ['token' => (string) Str::uuid(), 'reason' => 'Return one', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'items' => [['sale_item_id' => $this->sale->items->first()->id, 'quantity' => '1']]])->assertRedirect();
        $this->get(route('manage.show', ['products', $this->product->id]))->assertOk()->assertSee('SALE RETURN')->assertSee('RET-')->assertSee('href="'.route('sales.show', $this->sale).'"', false);
        $returnMovement = StockMovement::where('reason', 'SALE RETURN')->with('saleReturn.sale')->firstOrFail();
        $this->assertSame($this->sale->id, $returnMovement->saleReturn->sale->id);
        $this->assertSame('18.000', $returnMovement->balance);
    }

    public function test_product_viewer_can_read_history_but_no_invoice_link_without_sales_access(): void
    {
        $role = Role::create(['name' => 'Product history viewer']);
        $role->permissions()->sync(Permission::whereIn('name', ['products.view', 'products.view_history'])->pluck('id'));
        $user = User::create(['name' => 'History viewer', 'username' => 'histviewer', 'email' => 'histviewer@example.test', 'password' => 'test-password', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user);
        $this->get(route('manage.show', ['products', $this->product->id]))->assertOk()->assertSee('Stock movement history')->assertSee($this->sale->invoice)->assertDontSee('href="'.route('sales.show', $this->sale).'"', false);
        $this->get(route('stock.edit', $this->product))->assertForbidden();
    }
}
