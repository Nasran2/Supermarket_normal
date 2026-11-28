<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocumentationService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Optional register', 'username' => 'optional-register', 'email' => 'optional-register@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
    }

    public static function disabledValues(): array
    {
        return ['false' => [false], 'missing' => [null]];
    }

    #[DataProvider('disabledValues')]
    public function test_disabled_or_missing_flag_hides_register_everywhere(?bool $enabled): void
    {
        config(['features.register_cashier' => $enabled]);
        $register = app(RegisterService::class)->open($this->cashier->id, '100');

        foreach (['register.view', 'register.open', 'register.close', 'register.movement', 'register.view_all', 'dashboard.register', 'reports.register', 'reports.register.pdf', 'reports.cash', 'reports.cash.export'] as $permission) {
            $this->assertFalse($this->cashier->hasPermission($permission), $permission);
            $this->assertArrayNotHasKey($permission, Permissions::visible());
        }
        $this->assertArrayNotHasKey('register', ReportService::titles());
        $this->assertArrayNotHasKey('cash', ReportService::titles());
        $guides = app(DocumentationService::class)->guides();
        $this->assertArrayNotHasKey('start-your-shift', $guides);
        $this->assertArrayNotHasKey('close-your-shift', $guides);

        foreach (['dashboard', 'pos.index', 'reports.index'] as $route) {
            $this->get(route($route))->assertOk()
                ->assertDontSee('data-register-toggle', false)
                ->assertDontSee('id="register-workflow"', false)
                ->assertDontSee('data-close-register', false)
                ->assertDontSee('Register status')
                ->assertDontSee('Register &amp; history', false)
                ->assertDontSee('REG-'.$register->id)
                ->assertDontSee(route('reports.show', 'register'), false)
                ->assertDontSee(route('reports.show', 'cash'), false);
        }
    }

    public function test_disabled_register_pages_actions_and_report_exports_are_forbidden(): void
    {
        config(['features.register_cashier' => false]);
        $register = app(RegisterService::class)->open($this->cashier->id, '100');
        foreach (['register.index', 'register.current-summary'] as $route) {
            $this->getJson(route($route))->assertForbidden();
        }
        $this->get(route('register.show', $register))->assertForbidden();
        foreach (['register.open', 'register.close', 'register.movement'] as $route) {
            $this->postJson(route($route), [])->assertForbidden();
        }
        foreach (['register', 'cash'] as $report) {
            foreach (['reports.show', 'reports.pdf', 'reports.export'] as $route) {
                $this->get(route($route, $report))->assertForbidden();
            }
        }
        $this->assertNull($register->fresh()->closed_at);
        $this->assertDatabaseCount('register_movements', 0);
    }

    public function test_checkout_works_without_manual_register_and_keeps_accounting_links(): void
    {
        config(['features.register_cashier' => false]);
        $this->assertNull(app(RegisterService::class)->current($this->cashier->id));
        $this->get(route('pos.index'))->assertOk()->assertSee('id="pos"', false)
            ->assertSee('data-register="1"', false)->assertDontSee('Open a register to start selling');
        $this->getJson(route('pos.products'))->assertOk();
        $this->assertDatabaseCount('registers', 0);

        $product = Product::create(['name' => 'Optional register product', 'sku' => 'OPTIONAL-REGISTER', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '5']);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => '1']], 'payments' => [['payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount' => '100', 'amount_paid' => '100']]];
        for ($i = 0; $i < 2; $i++) {
            $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
            $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        }
        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('registers', 1);
        $register = app(RegisterService::class)->current($this->cashier->id);
        $this->assertSame('0.00', $register->opening_cash);
        $this->assertSame('200.00', app(RegisterService::class)->summary($register)['expected']);
        $sale = Sale::first();
        $this->post(route('sales.returns.store', $sale), [
            'token' => (string) Str::uuid(), 'reason' => 'Returned unopened',
            'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'),
            'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('100.00', $sale->fresh()->returned_total);
        $this->assertSame('100.00', app(RegisterService::class)->summary($register)['expected']);
        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee('REG-'.$register->id);
        $this->get(route('purchases.create'))->assertOk()->assertDontSee('Take cash from open register drawer');
        foreach (['returns', 'collections'] as $kind) {
            $report = app(ReportService::class)->build($kind, ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
            $this->assertNotContains('Register', $report['headers']);
            foreach ($report['query']->get() as $record) {
                $row = $report['map']($record);
                $this->assertCount(count($report['headers']), $row);
                $this->assertNotContains('REG-'.$register->id, $row);
            }
        }
    }

    public function test_enabled_flag_preserves_manual_open_close_and_register_reports(): void
    {
        config(['features.register_cashier' => true]);
        $this->get(route('pos.index'))->assertOk()->assertSee('Open a register to start selling');
        $this->postJson(route('register.open'), ['opening_cash' => '100'])->assertOk();
        $this->get(route('pos.index'))->assertOk()->assertSee('data-close-register', false);
        $this->getJson(route('register.current-summary'))->assertOk();
        $this->get(route('reports.show', 'register'))->assertOk();
        $this->get(route('reports.show', 'cash'))->assertOk();
        $this->postJson(route('register.close'), ['actual_cash' => '100'])->assertOk();
        $this->assertNull(app(RegisterService::class)->current($this->cashier->id));
    }
}
