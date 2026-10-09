<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleRevision;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\SaleRevisionService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Editor cashier', 'username' => 'editor', 'email' => 'editor@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000');
        $this->product = Product::create(['name' => 'Editable groceries', 'sku' => 'EDIT1', 'category_id' => Category::firstOrCreate(['name' => 'Groceries'])->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '10']);
    }

    private function method(string $type = 'CASH'): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function sale(string $method = 'CASH'): Sale
    {
        $data = ['items' => [['product_id' => $this->product->id, 'quantity' => '2']], 'discount' => '10', 'payments' => [['payment_method_id' => $this->method($method), 'amount' => '190']]];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['payments'][0]['amount_paid'] = $method === 'CASH' ? '200' : $quote['customer_payable'];
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    private function data(Sale $sale, string $quantity = '3', string $amount = '280', ?array $payments = null): array
    {
        return ['version' => app(SaleRevisionService::class)->version($sale->fresh()), 'items' => [['product_id' => $this->product->id, 'unit_id' => $this->product->unit_id, 'quantity' => $quantity, 'unit_price' => '100']], 'bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '20', 'notes' => 'Revised invoice', 'payments' => $payments ?? [['payment_method_id' => $this->method(), 'amount' => $amount]]];
    }

    private function quoted(Sale $sale, array $data): array
    {
        $quote = $this->postJson(route('sales.edit.quote', $sale), $data)->assertOk()->json();
        foreach ($data['payments'] as $i => &$payment) {
            $payment['amount_paid'] = $quote['payments'][$i]['customer_payable'];
        }
        unset($payment);

        return $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']];
    }

    public function test_pos_editor_seed_includes_saved_items_and_discount_without_changing_them(): void
    {
        $sale = $this->sale();
        $this->get(route('sales.edit', $sale))->assertOk()->assertSee('Edit '.$sale->invoice)->assertSee('id="sale-edit-seed"', false)->assertSee('Review payment')->assertSee('Reset changes')->assertDontSee('Edit sale details')->assertSee('"quantity":"2.000"', false)->assertSee('"value":"10.00"', false);
        $this->getJson(route('sales.edit.products', $sale))->assertOk()->assertJsonFragment(['stock' => '10.000']);
        $this->assertSame('8.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('sale_revisions', 0);
    }

    public function test_revision_updates_original_invoice_stock_and_register_once_and_preserves_history(): void
    {
        $sale = $this->sale();
        $time = $sale->sold_at->toDateTimeString();
        $token = $sale->checkout_token;
        $data = $this->quoted($sale, $this->data($sale));
        $this->postJson(route('sales.revise', $sale), $data)->assertOk()->assertJsonPath('invoice', $sale->invoice);
        $new = $sale->fresh();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame($token, $new->checkout_token);
        $this->assertSame($time, $new->sold_at->toDateTimeString());
        $this->assertSame('280.00', $new->sale_amount);
        $this->assertSame('120.00', $new->cost_total);
        $this->assertSame('7.000', $this->product->fresh()->stock);
        $this->assertSame('1280.00', app(RegisterService::class)->summary($new->register)['expected']);
        $this->assertSame('160.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $revision = SaleRevision::firstOrFail();
        $this->assertSame('190.00', $revision->before['sale_amount']);
        $this->assertSame('280.00', $revision->after['sale_amount']);
        $this->assertSame('10.00', $revision->before['payments'][0]['change']);
        $this->postJson(route('sales.revise', $sale), $data)->assertOk();
        $this->assertDatabaseCount('sale_revisions', 1);
        $this->assertSame('7.000', $this->product->fresh()->stock);
        $this->delete(route('sales.destroy', $sale), ['reason' => 'Cancel revised invoice'])->assertRedirect();
        $this->assertSame('10.000', $this->product->fresh()->stock);
    }

    public function test_quote_credits_original_stock_but_rejects_excess_and_failed_save_rolls_back(): void
    {
        $sale = $this->sale();
        $this->product->update(['stock' => '0']);
        $this->product->stockLayers()->update(['remaining_quantity' => '0']);
        $data = $this->data($sale, '2', '180');
        $valid = $this->quoted($sale, $data);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->postJson(route('sales.edit.quote', $sale), $this->data($sale))->assertUnprocessable();
        $valid['quote_hash'] = str_repeat('0', 64);
        $this->postJson(route('sales.revise', $sale), $valid)->assertUnprocessable();
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertSame('190.00', $sale->fresh()->sale_amount);
        $this->assertSame('200.00', $sale->payments->first()->amount_paid);
        $this->assertDatabaseCount('sale_revisions', 0);
    }

    public function test_removing_original_and_adding_converted_product_updates_both_stock_balances(): void
    {
        $sale = $this->sale();
        $dozen = Unit::where('name', 'Dozen')->firstOrFail();
        $newProduct = Product::create(['name' => 'Tea with dozens', 'sku' => 'EDIT-TEA', 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'price' => '100', 'cost' => '40', 'stock' => '30']);
        $newProduct->conversions()->create(['unit_id' => $dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => '1100']);
        $data = $this->data($sale, '1', '1080');
        $data['items'] = [['product_id' => $newProduct->id, 'unit_id' => $dozen->id, 'quantity' => '1']];
        $saved = $this->quoted($sale, $data);
        $this->postJson(route('sales.revise', $sale), $saved)->assertOk();
        $this->assertSame('10.000', $this->product->fresh()->stock);
        $this->assertSame('18.000', $newProduct->fresh()->stock);
        $this->assertSame('480.00', $sale->fresh()->cost_total);
        $this->assertSame('12.000', $sale->fresh()->items->first()->base_quantity);
        // Later conversion changes must not affect reversal of the revised invoice.
        $newProduct->conversions()->update(['base_quantity' => '6']);
        $this->delete(route('sales.destroy', $sale), ['reason' => 'Reverse converted edit'])->assertRedirect();
        $this->assertSame('30.000', $newProduct->fresh()->stock);
        $this->assertSame('10.000', $this->product->fresh()->stock);
    }

    public function test_stale_invoice_or_changed_prices_prevent_revision_and_preserve_original(): void
    {
        $sale = $this->sale();
        $data = $this->quoted($sale, $this->data($sale));
        $sale->update(['notes' => 'Changed in another window']);
        $this->postJson(route('sales.revise', $sale), $data)->assertUnprocessable()->assertJsonValidationErrors('sale');
        $fresh = $this->quoted($sale, $this->data($sale));
        $this->product->stockLayers()->update(['selling_price' => '101']);
        $this->postJson(route('sales.revise', $sale), $fresh)->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame('8.000', $this->product->fresh()->stock);
        $this->assertSame('190.00', $sale->fresh()->sale_amount);
        $this->assertDatabaseCount('sale_revisions', 0);
    }

    public function test_repeated_card_entries_rebuild_business_fees_and_remove_original_cash_total(): void
    {
        $method = PaymentMethod::findOrFail($this->method('CARD'));
        $method->rules()->delete();
        PaymentChargeRule::create(['payment_method_id' => $method->id, 'name' => 'Editor fee', 'minimum_amount' => '0', 'comparison_operator' => 'GTE', 'charge_type' => 'PERCENTAGE', 'charge_value' => '5', 'charge_bearer' => 'BUSINESS', 'active' => true]);
        $sale = $this->sale('CARD');
        $data = $this->quoted($sale, $this->data($sale, '3', '280', [['payment_method_id' => $method->id, 'amount' => '100'], ['payment_method_id' => $method->id, 'amount' => '180']]));
        $this->postJson(route('sales.revise', $sale), $data)->assertOk();
        $this->assertSame(2, $sale->payments()->count());
        $this->assertSame('14.00', Money::sum(Expense::where('status', 'ACTIVE')->pluck('amount')));
        $this->assertSame('9.50', Money::sum(Expense::where('status', 'REVERSED')->pluck('amount')));
        $this->assertSame('1000.00', app(RegisterService::class)->summary($sale->register)['expected']);
        $this->assertSame('146.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $again = $this->quoted($sale, $this->data($sale, '2', '180'));
        $this->postJson(route('sales.revise', $sale), $again)->assertOk();
        $this->assertSame(0, Expense::where('status', 'ACTIVE')->count());
        $this->assertSame('1180.00', app(RegisterService::class)->summary($sale->register)['expected']);
        $this->assertDatabaseCount('sale_revisions', 2);
    }

    public function test_returns_later_collections_closed_register_and_other_cashier_block_editing(): void
    {
        $sale = $this->sale();
        $data = $this->data($sale);
        $other = User::create(['name' => 'Other editor', 'username' => 'othereditor', 'email' => 'othereditor@example.test', 'password' => 'test-password', 'role_id' => $this->cashier->role_id]);
        $this->flushSession();
        $this->actingAs($other)->getJson(route('sales.edit', $sale))->assertUnprocessable();
        $this->flushSession();
        $this->actingAs($this->cashier);
        $this->post(route('sales.returns.store', $sale), ['token' => (string) Str::uuid(), 'reason' => 'Return first', 'payment_method_id' => $this->method(), 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]])->assertRedirect();
        $this->postJson(route('sales.edit.quote', $sale), $data)->assertUnprocessable();
        $due = $this->sale();
        $due->payments()->update(['amount_paid' => '100', 'change' => '0']);
        $this->post(route('sales.collections.store', $due), ['token' => (string) Str::uuid(), 'payment_method_id' => $this->method(), 'amount' => '10', 'amount_paid' => '10'])->assertRedirect();
        $this->getJson(route('sales.edit', $due))->assertUnprocessable();
        $closed = $this->sale();
        app(RegisterService::class)->close($closed->register, '1000', null);
        $this->getJson(route('sales.edit', $closed))->assertUnprocessable();
    }

    public function test_edit_permission_works_without_new_sale_permission_and_tokens_cannot_cross_invoices(): void
    {
        $sale = $this->sale();
        $second = $this->sale();
        $role = Role::create(['name' => 'Invoice editor only']);
        $role->permissions()->sync(Permission::whereIn('name', ['sales.edit', 'sales.view', 'pos.access', 'settings.pos', 'pos.discount', 'pos.override_price', 'pos.split_payment'])->pluck('id'));
        $this->cashier->update(['role_id' => $role->id]);
        $this->actingAs($this->cashier->fresh());
        $this->get(route('sales.edit', $sale))->assertOk();
        $data = $this->quoted($sale, $this->data($sale));
        $this->postJson(route('sales.revise', $sale), $data)->assertOk();
        $this->postJson(route('sales.revise', $second), $data)->assertForbidden();
        $role->permissions()->detach();
        $this->actingAs($this->cashier->fresh());
        $this->postJson(route('sales.revise', $sale), $data)->assertForbidden();
    }
}
