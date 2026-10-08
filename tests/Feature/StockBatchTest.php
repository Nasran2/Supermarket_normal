<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReportService;
use App\Services\StockService;
use App\Support\Sidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $a;

    private Product $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Stock QA', 'username' => 'stock-qa', 'email' => 'stock@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        $common = ['category_id' => Category::first()->id, 'cost' => '40', 'price' => '100', 'stock' => '20', 'active' => true];
        $this->a = Product::create($common + ['name' => 'Stock biscuits', 'sku' => 'STOCK-A', 'unit_id' => Unit::where('short_name', 'pcs')->value('id')]);
        $this->b = Product::create($common + ['name' => 'Stock rice', 'sku' => 'STOCK-B', 'unit_id' => Unit::where('short_name', 'kg')->value('id')]);
    }

    private function row(Product $p, array $changes = []): array
    {
        $p->refresh();

        return $changes + ['product_id' => $p->id, 'unit_id' => $p->unit_id, 'expected_stock' => $p->stock, 'expected_price' => $p->price, 'expected_cost' => $p->cost, 'mode' => 'ADD', 'quantity' => '1'];
    }

    private function create(array $rows): StockAdjustment
    {
        $this->post(route('adjustments.store'), ['reason' => 'QA count', 'items' => $rows])->assertSessionHasNoErrors()->assertRedirect();

        return StockAdjustment::latest('id')->firstOrFail();
    }

    public function test_multi_product_adjustment_updates_quantity_prices_cost_and_history_together(): void
    {
        $batch = $this->create([$this->row($this->a, ['quantity' => '5', 'price' => '120', 'cost' => '45']), $this->row($this->b, ['mode' => 'SET', 'quantity' => '18.75', 'price' => null, 'cost' => '0'])]);
        $this->assertSame('25.000', $this->a->fresh()->stock);
        $this->assertSame('120.00', $this->a->fresh()->price);
        $this->assertSame('45.00', $this->a->fresh()->cost);
        $this->assertSame('18.750', $this->b->fresh()->stock);
        $this->assertSame('100.00', $this->b->fresh()->price);
        $this->assertSame('0.00', $this->b->fresh()->cost);
        $this->assertSame(2, $batch->items()->count());
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.batch.create', 'subject_id' => $batch->id]);
        $this->get(route('adjustments.show', $batch))->assertOk()->assertSee('Stock biscuits')->assertSee('Stock rice')->assertSee('120.00');
        $this->get(route('adjustments.index', ['q' => $batch->reference, 'status' => 'ACTIVE']))->assertOk()->assertSee($batch->reference);
    }

    public function test_invalid_later_row_rolls_back_entire_batch_and_rejects_duplicates_fractional_pieces_and_stale_stock(): void
    {
        $this->postJson(route('adjustments.store'), ['reason' => 'QA count', 'items' => [$this->row($this->a, ['price' => '120']), $this->row($this->b, ['mode' => 'REMOVE', 'quantity' => '21'])]])->assertUnprocessable();
        $this->assertSame('20.000', $this->a->fresh()->stock);
        $this->assertSame('100.00', $this->a->fresh()->price);
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        foreach ([[$this->row($this->a), $this->row($this->a)], [$this->row($this->a, ['quantity' => '0.5'])], [$this->row($this->a, ['expected_stock' => '19'])], [$this->row($this->a, ['price' => '-1'])]] as $rows) {
            $this->postJson(route('adjustments.store'), ['reason' => 'QA count', 'items' => $rows])->assertUnprocessable();
        }
    }

    public function test_revision_and_reversal_preserve_intervening_stock_movements_and_restore_prices(): void
    {
        $batch = $this->create([$this->row($this->a, ['quantity' => '5', 'price' => '120', 'cost' => '45'])]);
        app(StockService::class)->move($this->a->fresh(), '-3', 'SALE', 'QA SALE', $this->admin->id);
        $this->put(route('adjustments.update', $batch), ['reason' => 'Corrected count', 'revision' => 1, 'items' => [$this->row($this->a, ['mode' => 'SET', 'quantity' => '24', 'price' => '125'])]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('24.000', $this->a->fresh()->stock);
        $this->assertSame('7.000', $batch->fresh()->items->first()->quantity_change);
        $this->assertSame(2, $batch->fresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.batch.update']);
        $this->delete(route('adjustments.destroy', $batch), ['revision' => 2, 'reason' => 'QA reversal'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('17.000', $this->a->fresh()->stock);
        $this->assertSame('100.00', $this->a->fresh()->price);
        $this->assertSame('40.00', $this->a->fresh()->cost);
        $this->assertSame('VOID', $batch->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.batch.reverse']);
        $this->deleteJson(route('adjustments.destroy', $batch), ['revision' => 3, 'reason' => 'Again'])->assertUnprocessable();
        $this->get(route('adjustments.edit', $batch))->assertStatus(409);
    }

    public function test_reversal_refuses_newer_prices_or_insufficient_stock_and_stale_revisions(): void
    {
        $batch = $this->create([$this->row($this->a, ['quantity' => '5', 'price' => '120']), $this->row($this->b, ['quantity' => '2'])]);
        $this->a->update(['price' => '130']);
        $this->deleteJson(route('adjustments.destroy', $batch), ['revision' => 1, 'reason' => 'QA reversal'])->assertUnprocessable();
        $this->assertSame('22.000', $this->b->fresh()->stock);
        $this->assertSame('ACTIVE', $batch->fresh()->status);
        $this->a->update(['price' => '120', 'stock' => '2']);
        $this->deleteJson(route('adjustments.destroy', $batch), ['revision' => 1, 'reason' => 'QA reversal'])->assertUnprocessable();
        $this->putJson(route('adjustments.update', $batch), ['reason' => 'Stale edit', 'revision' => 2, 'items' => [$this->row($this->a), $this->row($this->b)]])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_price_only_adjustment_and_zero_count_work_without_quantity_movements_for_price_only(): void
    {
        $batch = $this->create([$this->row($this->a, ['quantity' => '0', 'price' => '90'])]);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('20.000', $this->a->fresh()->stock);
        $this->delete(route('adjustments.destroy', $batch), ['revision' => 1, 'reason' => 'QA reversal'])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $this->a->fresh()->price);
        $this->create([$this->row($this->a, ['mode' => 'SET', 'quantity' => '0'])]);
        $this->assertSame('0.000', $this->a->fresh()->stock);
    }

    public function test_sidebar_has_all_report_sublinks_and_existing_crud_links_and_enforces_permissions(): void
    {
        $sections = Sidebar::sections($this->admin);
        $reports = collect($sections['MANAGEMENT'])->firstWhere('label', 'Reports');
        $this->assertCount(count(ReportService::TITLES) + 1, $reports['children']);
        foreach (ReportService::TITLES as $key => $title) {
            $this->get(route('reports.show', $key))->assertOk()->assertSee($title);
        }
        $products = collect($sections['INVENTORY'])->firstWhere('label', 'Products');
        $this->assertContains(route('manage.create', 'products'), array_column($products['children'], 'url'));
        $this->assertContains(route('adjustments.create'), array_column($products['children'], 'url'));
        $cashier = User::create(['name' => 'Viewer', 'username' => 'stock-viewer', 'email' => 'viewer@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->flushSession();
        $this->actingAs($cashier);
        $this->get(route('adjustments.index'))->assertOk();
        $this->get(route('adjustments.create'))->assertForbidden();
        $this->getJson(route('adjustments.products'))->assertForbidden();
        $this->postJson(route('adjustments.store'), ['reason' => 'Forbidden', 'items' => [$this->row($this->a)]])->assertForbidden();
        $nav = Sidebar::sections($cashier);
        $this->assertEmpty(collect($nav['MANAGEMENT'])->firstWhere('label', 'Reports')['children']);
        $this->assertNotContains(route('adjustments.create'), array_column(collect($nav['INVENTORY'])->firstWhere('label', 'Products')['children'], 'url'));
    }

    public function test_adjustment_history_protects_primary_units_and_fractional_unit_history(): void
    {
        $this->a->update(['stock' => '0']);
        $this->create([$this->row($this->a, ['quantity' => '0', 'price' => '90'])]);
        $this->putJson(route('manage.update', ['resource' => 'products', 'id' => $this->a->id]), ['name' => $this->a->name, 'sku' => $this->a->sku, 'category_id' => $this->a->category_id, 'unit_id' => $this->b->unit_id, 'cost' => '40', 'price' => '90', 'stock' => '0', 'low_stock' => '0', 'active' => true])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $batch = $this->create([$this->row($this->b, ['quantity' => '0.5'])]);
        $this->delete(route('adjustments.destroy', $batch), ['revision' => 1, 'reason' => 'QA reversal'])->assertSessionHasNoErrors();
        $unit = $this->b->unit;
        $this->putJson(route('manage.update', ['resource' => 'units', 'id' => $unit->id]), ['name' => $unit->name, 'short_name' => $unit->short_name, 'allow_decimal' => false, 'active' => true])->assertUnprocessable()->assertJsonValidationErrors('allow_decimal');
        $this->get(route('adjustments.index', ['to' => today()->toDateString()]))->assertOk();
    }
}
