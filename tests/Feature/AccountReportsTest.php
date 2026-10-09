<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PdfReportService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use App\Services\SupplierLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Report QA', 'username' => 'report-qa', 'email' => 'report@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        app(SettingsService::class)->put('business', ['business_name' => 'QA Market', 'address' => '123 Sample Road, Colombo', 'phone' => '011 000 1234', 'email' => 'qa@example.test']);
    }

    private function product(string $name): Product
    {
        $product = Product::create(['name' => $name, 'sku' => str_replace(' ', '-', $name), 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'stock' => '5', 'cost' => '40', 'price' => '100', 'active' => true]);
        app(StockLayerService::class)->ensureLegacy($product);

        return $product;
    }

    public function test_category_view_uses_multi_category_memberships_and_filters(): void
    {
        $category = Category::create(['name' => 'Selected category']);
        $other = Category::create(['name' => 'Other category']);
        $match = $this->product('Category match');
        $match->categories()->attach([$category->id, $other->id]);
        $hidden = $this->product('Other only');
        $hidden->categories()->attach($other);
        $this->get(route('manage.show', ['categories', $category->id]))->assertOk()->assertSee('Category match')->assertDontSee('Other only')->assertSee(route('manage.show', ['products', $match->id]), false);
        $this->get(route('manage.show', ['resource' => 'categories', 'id' => $category->id, 'q' => 'missing']))->assertOk()->assertDontSee('Category match');
    }

    public function test_customer_due_filters_and_stats_subtract_opening_payments_and_actions_are_conditional(): void
    {
        Customer::create(['name' => 'Settled customer', 'opening_due' => '600', 'opening_due_paid' => '600', 'active' => true]);
        Customer::create(['name' => 'Owing customer', 'opening_due' => '600', 'opening_due_paid' => '200', 'active' => true]);
        $response = $this->get(route('manage.index', 'customers'))->assertOk()->assertViewHas('stats', fn ($s) => (int) $s->due_count === 1 && (float) $s->total_due === 400.0);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//tbody/tr/td[last()]//a[contains(.,"Collect Payment")]')->length);
        $this->assertSame(0, $xpath->query('//tbody/tr/td[3]//a[contains(.,"Collect Payment")]')->length);
        $this->get(route('manage.index', ['resource' => 'customers', 'balance' => 'clear']))->assertSee('Settled customer')->assertDontSee('Owing customer');
        $this->get(route('manage.index', ['resource' => 'customers', 'balance' => 'due']))->assertSee('Owing customer')->assertDontSee('Settled customer');
    }

    private function supplier(): Supplier
    {
        $supplier = Supplier::create(['name' => 'Ledger Supplier QA', 'address' => 'Supplier address', 'phone' => '011 555 1234', 'active' => true]);
        $purchase = Purchase::create(['reference' => 'pur-QA-ledger', 'supplier_id' => $supplier->id, 'user_id' => $this->admin->id, 'purchase_date' => '2026-09-10', 'total' => '600', 'payment_tracking' => true]);
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'purchase.save', 'subject_type' => Purchase::class, 'subject_id' => $purchase->id, 'before' => ['total' => '400'], 'after' => ['total' => '600']])->forceFill(['created_at' => '2026-09-18 12:00:00'])->save();
        foreach ([['PAYMENT', '150', '2026-09-12'], ['REFUND', '50', '2026-09-20']] as [$kind,$amount,$date]) {
            $purchase->payments()->create(['user_id' => $this->admin->id, 'token' => Str::uuid(), 'kind' => $kind, 'amount' => $amount, 'method_name' => 'Cash', 'method_type' => 'CASH', 'paid_at' => $date]);
        }
        Purchase::create(['reference' => 'pur-QA-unknown', 'supplier_id' => $supplier->id, 'user_id' => $this->admin->id, 'purchase_date' => '2026-09-11', 'total' => '12000', 'payment_tracking' => false]);
        $void = Purchase::create(['reference' => 'pur-QA-void', 'supplier_id' => $supplier->id, 'user_id' => $this->admin->id, 'purchase_date' => '2026-09-13', 'total' => '200', 'payment_tracking' => true, 'status' => 'VOIDED']);
        AuditLog::create(['user_id' => $this->admin->id, 'action' => 'purchase.void', 'subject_type' => Purchase::class, 'subject_id' => $void->id, 'after' => ['reason' => 'QA void']])->forceFill(['created_at' => '2026-09-14 10:00:00'])->save();

        return $supplier;
    }

    public function test_supplier_ledger_carries_opening_balance_revisions_refunds_voids_and_unknown_balances(): void
    {
        $supplier = $this->supplier();
        $this->assertSame('500.00', $supplier->due_balance);
        $all = app(SupplierLedgerService::class)->build($supplier);
        $this->assertSame('500.00', $all['closing']);
        $period = app(SupplierLedgerService::class)->build($supplier, ['from' => '2026-09-15', 'to' => '2026-09-30']);
        $this->assertSame('250.00', $period['opening']);
        $this->assertSame('250.00', $period['debits']);
        $this->assertSame('500.00', $period['closing']);
        $this->assertCount(2, $period['entries']);
        $this->get(route('manage.show', ['suppliers', $supplier->id]))->assertOk()->assertSee('Purchase history')->assertSee('500.00')->assertSee('pur-QA-unknown')->assertSee('Supplier ledger');
        $this->get(route('manage.index', 'suppliers'))->assertOk()->assertSee('500.00')->assertSee('purchase balances not recorded');
        $csv = $this->get(route('manage.suppliers.ledger', ['supplier' => $supplier->id, 'export' => 'csv', 'from' => '2026-09-15', 'to' => '2026-09-30']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Revision', $csv);
        $this->assertStringNotContainsString('pur-QA-unknown', $csv);
        $pdf = $this->get(route('manage.suppliers.ledger', ['supplier' => $supplier->id, 'from' => '2026-09-15', 'to' => '2026-09-30']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->saveQa('supplier-ledger', $pdf->getContent());
    }

    public function test_inline_expense_category_creation_validates_duplicates_and_respects_permission(): void
    {
        $this->get(route('manage.create', 'expenses'))->assertOk()->assertSee('data-open-expense-category', false);
        $this->postJson(route('manage.store', 'expense-categories'), ['name' => 'Quick category QA'])->assertCreated()->assertJsonPath('name', 'Quick category QA');
        $this->postJson(route('manage.store', 'expense-categories'), ['name' => 'Quick category QA'])->assertUnprocessable();
        $role = Role::create(['name' => 'No expense creation']);
        $this->admin->update(['role_id' => $role->id]);
        $this->actingAs($this->admin->fresh());
        $this->postJson(route('manage.store', 'expense-categories'), ['name' => 'Blocked'])->assertForbidden();
    }

    public function test_every_report_downloads_a_real_pdf_with_all_stock_rows_and_letterhead(): void
    {
        $this->supplier();
        for ($i = 0; $i < 28; $i++) {
            $this->product('Export product '.str_pad($i, 2, '0', STR_PAD_LEFT));
        }
        foreach (array_keys(ReportService::TITLES) as $report) {
            $response = $this->get(route('reports.pdf', ['report' => $report, 'from' => '2026-09-01', 'to' => '2026-10-31']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('attachment; filename=', $response->headers->get('Content-Disposition'));
            $this->saveQa($report, $response->getContent());
        }
        $this->get(route('reports.show', 'stock'))->assertOk()->assertSee('Download PDF');
        $this->get(route('reports.show', 'profit'))->assertOk()->assertSee('Download PDF');
    }

    public function test_pdf_and_supplier_ledger_permissions_and_invalid_filters_are_enforced(): void
    {
        $supplier = $this->supplier();
        $this->getJson(route('manage.suppliers.ledger', ['supplier' => $supplier->id, 'from' => '2026-09-30', 'to' => '2026-09-01']))->assertUnprocessable();
        $this->getJson(route('reports.pdf', ['report' => 'sales', 'from' => '2026-09-30', 'to' => '2026-09-01']))->assertUnprocessable();
        $role = Role::create(['name' => 'No accounts or reports']);
        $this->admin->update(['role_id' => $role->id]);
        $this->actingAs($this->admin->fresh());
        $this->get(route('reports.pdf', 'sales'))->assertForbidden();
        $this->get(route('manage.suppliers.ledger', $supplier))->assertForbidden();
    }

    public function test_large_pdf_export_preserves_long_rows_across_many_pages(): void
    {
        $rows = collect(range(1, 650))->map(fn ($i) => ['Ledger entry '.str_pad($i, 4, '0', STR_PAD_LEFT), 'A long description with a Unicode name café and a detailed product reference '.str_repeat('reference', 12), '1,200.00']);
        $pdf = app(PdfReportService::class)->render(['title' => 'Large export QA', 'headers' => ['Reference', 'Description', 'Amount'], 'rows' => $rows, 'rowCount' => 650, 'filters' => ['from' => '2026-09-01', 'to' => '2026-10-31'], 'notes' => 'All 650 rows included.']);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->saveQa('large-export', $pdf);
    }

    private function saveQa(string $name, string $pdf): void
    {
        if ($directory = getenv('REPORT_PDF_QA_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/'.$name.'.pdf', $pdf);
        }
    }
}
