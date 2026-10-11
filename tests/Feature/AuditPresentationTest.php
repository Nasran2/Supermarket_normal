<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_ignore_storage_casts_and_show_readable_amounts_switches_and_categories(): void
    {
        $before = ['sku' => '001234', 'price' => '5.00', 'active' => 1, 'track_stock' => '0', 'stock' => '0.000', 'categories' => [14, 15]];
        $after = ['sku' => '001234', 'price' => '7.50', 'active' => true, 'track_stock' => false, 'stock' => '0', 'categories' => ['15', '14'], 'updated_at' => '2026-10-11 13:37:39'];
        $comparison = AuditPresentation::compare($before, $after);
        $main = collect($comparison['fields'])->where('detail', false)->values();
        $this->assertCount(1, $main);
        $this->assertSame('Selling price', $main[0]['label']);
        $this->assertSame('Rs. 5.00', $main[0]['before']);
        $this->assertSame('Rs. 7.50', $main[0]['after']);
        $fields = collect($comparison['fields'])->keyBy('label');
        $this->assertSame('001234', $fields['SKU']['after']);
        $this->assertSame('Yes', $fields['Active']['after']);
        $this->assertSame('No', $fields['Maintain stock']['after']);
        $this->assertSame('#15, #14', $fields['Categories']['after']);
        $this->assertFalse($fields['Categories']['changed']);
    }

    public function test_empty_missing_and_zero_values_remain_distinct(): void
    {
        $comparison = AuditPresentation::compare([], ['price' => '0.00', 'active' => false, 'barcode' => null, 'categories' => []]);
        $this->assertTrue($comparison['before_empty']);
        $fields = collect($comparison['fields'])->keyBy('label');
        $this->assertSame('Not recorded', $fields['Selling price']['before']);
        $this->assertSame('Rs. 0.00', $fields['Selling price']['after']);
        $this->assertSame('No', $fields['Active']['after']);
        $this->assertSame('Not set', $fields['Barcode']['after']);
        $this->assertSame('None', $fields['Categories']['after']);
        $this->assertSame('No values recorded', AuditPresentation::text([]));
    }

    public function test_older_settings_snapshots_decode_values_and_omitted_uploads_are_unchanged(): void
    {
        $before = ['business_name' => '"Sample market"', 'phone' => '"0770001234"', 'logo' => '"branding\\/sample.png"', 'show_email_receipt' => 'false', 'city' => 'null'];
        $after = ['business_name' => 'Sample market', 'phone' => '0770001234', 'show_email_receipt' => true, 'city' => null];
        $comparison = AuditPresentation::compare($before, $after, [], 'settings.business');
        $main = collect($comparison['fields'])->where('detail', false)->values();
        $this->assertCount(1, $main);
        $this->assertSame('No', $main[0]['before']);
        $this->assertSame('Yes', $main[0]['after']);
        $row = AuditPresentation::formatRow(['', '', 'settings.business', '', json_encode($before), json_encode($after)]);
        $this->assertStringContainsString('Telephone: 0770001234', $row[4]);
        $this->assertStringContainsString('Business logo: branding/sample.png', $row[5]);
    }

    public function test_nested_values_percentages_and_dates_are_labelled_without_json(): void
    {
        $snapshot = ['default_discount_type' => 'PERCENT', 'default_discount_value' => '12.50', 'conversions' => [['unit_id' => 2, 'price' => '45.00', 'base_quantity' => '12.000']], 'expense_date' => '2026-10-11', 'updated_at' => '2026-10-11 13:37:39'];
        $text = AuditPresentation::text($snapshot, ['currency_symbol' => 'LKR', 'date_format' => 'd/m/Y']);
        $this->assertStringContainsString('Default discount: 12.5%', $text);
        $this->assertStringContainsString('Conversions → 1 → Selling price: LKR 45.00', $text);
        $this->assertStringContainsString('Conversions → 1 → Base Quantity: 12', $text);
        $this->assertStringContainsString('Expense Date: 11/10/2026', $text);
        $this->assertStringContainsString('Updated on: 11/10/2026 01:37 PM', $text);
        $this->assertStringNotContainsString('{', $text);
        $nested = AuditPresentation::text(['items' => [['discount_type' => 'PERCENT', 'discount_value' => '12.50', 'cost_total' => '80.00']]]);
        $this->assertStringContainsString('Items → 1 → Discount Value: 12.5%', $nested);
        $this->assertStringContainsString('Items → 1 → Cost Total: Rs. 80.00', $nested);
        $this->assertSame('Opened At: 11/10/2026 12:30 AM', AuditPresentation::text(['opened_at' => '2026-10-10T19:00:00Z'], ['timezone' => 'Asia/Colombo']));
    }

    public function test_audit_page_and_csv_are_readable_and_leave_original_snapshots_intact(): void
    {
        $this->seed();
        $admin = User::create(['name' => 'Audit QA', 'email' => 'audit-qa@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($admin);
        $before = ['name' => 'Shopping bags', 'price' => '5.00', 'track_stock' => '0', 'categories' => [], 'sku' => '001234'];
        $after = ['name' => 'Shopping bags <script>alert(1)</script>', 'price' => '7.50', 'track_stock' => false, 'categories' => ['14'], 'sku' => '001234'];
        $entry = AuditLog::create(['user_id' => $admin->id, 'action' => 'products.save', 'subject_type' => Product::class, 'subject_id' => 99, 'before' => $before, 'after' => $after]);
        $this->get(route('reports.show', 'audit'))->assertOk()
            ->assertSee('Selling price')->assertSee('Rs. 5.00')->assertSee('Rs. 7.50')
            ->assertSee('Other details')->assertSee('Maintain stock')->assertSee('No')
            ->assertSee('Shopping bags &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('"track_stock"', false);
        $csv = $this->get(route('reports.export', 'audit'))->assertOk()->streamedContent();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream);
        $row = fgetcsv($stream);
        fclose($stream);
        $this->assertStringContainsString('Selling price: Rs. 5.00', $row[4]);
        $this->assertStringContainsString('Categories: #14', $row[5]);
        $this->assertStringContainsString('SKU: 001234', $row[5]);
        $this->assertSame($before, $entry->fresh()->before);
        $this->assertSame($after, $entry->fresh()->after);
        $this->get(route('reports.pdf', 'audit'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
