<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\RepairBrand;
use App\Models\RepairJob;
use App\Models\Role;
use App\Models\SmsMessage;
use App\Models\SuperAdmin;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\Repair\RepairReporting;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\SmsSettingsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhoneRepairTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->assertFalse(Route::has('repairs.index'));
        config(['services.phone_rep' => true]);
        Route::middleware('web')->group(base_path('routes/repairs.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->admin = User::whereHas('role', fn ($q) => $q->where('name', 'Administrator'))->firstOrFail();
        $this->flushSession();
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Repair Customer', 'phone' => '0771234567', 'active' => true]);
    }

    private function intake(array $overrides = []): RepairJob
    {
        $data = $overrides + [
            'submission' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'brand' => 'Apple', 'model' => 'iPhone 14',
            'serial' => 'Private-serial-123', 'faults' => 'Cracked screen', 'notes' => 'Internal note only',
            'received_at' => today()->toDateString(), 'due_at' => today()->addDay()->toDateString(), 'warranty_days' => 30,
            'priority' => 'NORMAL', 'checklist' => [['label' => 'Screen', 'result' => 'Not working']],
        ];
        $this->post(route('repairs.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $job = RepairJob::where('submission', $data['submission'])->firstOrFail();
        $this->post(route('repairs.store'), $data)->assertRedirect(route('repairs.show', $job));

        return $job;
    }

    private function setJobStatus(RepairJob $job, string $status, array $fields = []): void
    {
        $this->put(route('repairs.update', $job), $fields + ['status' => $status])->assertSessionHasNoErrors()->assertRedirect();
    }

    private function payment(string $amount, string $type = 'CASH'): array
    {
        return ['submission' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => PaymentMethod::where('type', $type)->value('id')];
    }

    public function test_intake_stock_payments_delivery_and_consolidated_reports(): void
    {
        $this->get(route('repairs.create'))->assertOk()->assertSee('Optional unlock credential');
        $this->getJson(route('repairs.customers', ['q' => 'Repair']))->assertOk()->assertJsonFragment(['name' => $this->customer->name]);
        $register = app(RegisterService::class)->open($this->admin->id, '100');
        $job = $this->intake(['unlock_type' => 'PIN', 'device_unlock' => '947293', 'estimate' => '800']);
        $this->get(route('repairs.show', $job))->assertOk()->assertDontSee('947293')->assertSee($job->tracking);
        $this->assertNotSame('947293', DB::table('repair_jobs')->where('id', $job->id)->value('device_unlock'));
        $this->postJson(route('repairs.unlock', $job))->assertOk()->assertJson(['type' => 'PIN', 'value' => '947293']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'repair.unlock.view']);
        Storage::fake('local');
        $this->post(route('repairs.attach', $job), ['file' => UploadedFile::fake()->image('screen.png')])->assertSessionHasNoErrors();
        $file = $job->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $this->get(route('repairs.download', [$job, $file]))->assertDownload('screen.png');
        $this->get(route('repairs.print', [$job, 'intake', 'size' => '80mm', 'barcode' => 1]))
            ->assertOk()->assertSee('data:image/png;base64,')->assertDontSee('947293')->assertDontSee('800.00');

        $advance = $this->payment('200');
        $this->post(route('repairs.payment', [$job, 'collect']), $advance)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('repairs.payment', [$job, 'collect']), $advance)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('200.00', $job->fresh()->paid);
        $this->assertSame('0.00', $this->customer->fresh()->due_balance);
        $this->assertSame('300.00', app(RegisterService::class)->summary($register)['expected']);
        $this->assertSame('0.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['revenue']);

        $product = Product::create(['name' => 'Repair OLED', 'sku' => 'REPAIR-OLED', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'),
            'stock' => 5, 'cost' => 150, 'price' => 450, 'low_stock' => 0, 'active' => true, 'available_for_pos' => false, 'available_for_repairs' => true]);
        $this->getJson(route('pos.products', ['q' => 'Repair OLED']))->assertOk()->assertDontSee('Repair OLED');
        $this->postJson(route('pos.quote'), ['items' => [['product_id' => $product->id, 'quantity' => 1]], ...$this->payment('100')])->assertUnprocessable();
        $this->getJson(route('repairs.products', ['q' => 'Repair OLED']))->assertOk()->assertJsonFragment(['name' => 'Repair OLED']);
        $plan = fn () => $this->post(route('repairs.parts.add', $job), ['product_id' => $product->id, 'quantity' => 1, 'submission' => (string) Str::uuid()]);
        $plan()->assertSessionHasNoErrors();
        $part = $job->parts()->latest('id')->first();
        $this->assertSame('5.000', $product->fresh()->stock);
        $this->post(route('repairs.parts.change', [$job, $part, 'consume']))->assertSessionHasNoErrors();
        $this->post(route('repairs.parts.change', [$job, $part, 'consume']))->assertSessionHasNoErrors();
        $this->assertSame('4.000', $product->fresh()->stock);
        $this->post(route('repairs.parts.change', [$job, $part, 'remove']))->assertSessionHasNoErrors();
        $this->post(route('repairs.parts.change', [$job, $part, 'remove']))->assertSessionHasNoErrors();
        $this->assertSame('5.000', $product->fresh()->stock);
        $plan()->assertSessionHasNoErrors();
        $part = $job->parts()->latest('id')->first();
        $this->post(route('repairs.parts.change', [$job, $part, 'consume']))->assertSessionHasNoErrors();
        $this->post(route('repairs.cost', $job), ['amount' => 40, 'payment_method_id' => $this->payment('40')['payment_method_id'], 'description' => 'Outside diagnostic', 'submission' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('4.000', $product->fresh()->stock);
        $this->setJobStatus($job, 'DIAGNOSING', ['diagnosis' => 'Broken OLED']);
        $this->setJobStatus($job, 'WAITING_APPROVAL');
        $this->setJobStatus($job, 'IN_PROGRESS', ['approved' => 1, 'labor' => 200, 'service_fee' => 30, 'discount' => 20, 'tax' => 40]);
        $this->setJobStatus($job, 'READY', ['approved' => 1]);
        $this->setJobStatus($job, 'COMPLETED', ['approved' => 1]);
        $this->assertSame('700.00', $job->fresh()->total);
        $this->assertSame('500.00', $this->customer->fresh()->due_balance);
        $this->get(route('repairs.print', [$job, 'invoice', 'size' => 'A4']))->assertOk()->assertSee('700.00')->assertDontSee('947293');

        $this->post(route('repairs.payment', [$job, 'collect']), $this->payment('501'))->assertSessionHasErrors('amount');
        $this->post(route('repairs.payment', [$job, 'collect']), $this->payment('500'))->assertSessionHasNoErrors();
        $original = $job->payments()->where('kind', 'PAYMENT')->firstOrFail();
        $replacement = $this->payment('450', 'CARD');
        $this->post(route('repairs.payment.correct', [$job, $original]), $replacement)->assertSessionHasErrors('reason');
        $replacement['reason'] = 'Corrected tender and counted amount';
        $this->post(route('repairs.payment.correct', [$job, $original]), $replacement)->assertSessionHasNoErrors();
        $this->post(route('repairs.payment.correct', [$job, $original]), $replacement)->assertSessionHasNoErrors();
        $this->assertSame('500.00', $original->fresh()->amount);
        $this->assertSame('-500.00', $original->reversal->amount);
        $this->assertSame('50.00', $this->customer->fresh()->due_balance);
        $this->post(route('repairs.payment', [$job, 'collect']), $this->payment('50'))->assertSessionHasNoErrors();
        $this->post(route('repairs.payment', [$job, 'refund']), $this->payment('70') + ['reason' => 'Partial goodwill credit'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->customer->fresh()->due_balance);
        $this->assertSame('0.00', app(CustomerLedgerService::class)->build($this->customer)->last()['balance']);
        $this->assertSame('240.00', app(RegisterService::class)->summary($register)['expected']);
        $this->setJobStatus($job, 'DELIVERED');
        $this->assertNull($job->fresh()->device_unlock);
        $this->assertSame(today()->addDays(30)->toDateString(), $job->fresh()->warranty_until->toDateString());

        $date = today()->toDateString();
        $summary = app(ProfitLossService::class)->calculate($date, $date);
        $this->assertSame('594.00', $summary['revenue']);
        $this->assertSame('150.00', $summary['cogs']);
        $this->assertSame('404.00', $summary['net']);
        $this->assertSame('630.00', Money::round(app(ReportService::class)->build('payments', ['from' => $date, 'to' => $date])['cards']['Net collections']));
        foreach (array_keys(RepairReporting::VIEWS) as $view) {
            $this->get(route('repairs.reports', ['view' => $view]))->assertOk();
            $this->get(route('repairs.reports', ['view' => $view, 'export' => 'csv']))->assertOk()->streamedContent();
        }
        $this->get(route('reports.show', ['report' => 'cash', 'from' => $date, 'to' => $date]))->assertOk()->assertSee('Repair REFUND')->assertSee('Repair expense');
        $this->get(route('reports.show', ['report' => 'expenses', 'from' => $date, 'to' => $date]))->assertOk()->assertSee('Outside diagnostic');
        $this->get(route('repairs.print', [$job, 'payment', 'payment' => $original->id]))->assertOk()->assertSee('500.00');
        $this->get(route('dashboard'))->assertOk()->assertSee('Repair desk');

        auth()->logout();
        $this->post(route('repairs.track'), ['tracking' => strtolower($job->tracking)])->assertOk()->assertSee('Delivered')
            ->assertDontSee($this->customer->name)->assertDontSee('Private-serial-123')->assertDontSee('Internal note only')->assertDontSee('947293');
    }

    public function test_permissions_repeat_intake_and_feature_gate_preserve_data(): void
    {
        $this->intake(['unlock_type' => 'PATTERN', 'device_unlock' => '1-2-5-8']);
        $job = RepairJob::firstOrFail();
        $this->getJson(route('repairs.customers.history', $this->customer))->assertOk()->assertJsonFragment(['serial' => 'Private-serial-123']);
        $repeat = $this->intake(['device_id' => $job->device_id]);
        $this->assertSame($repeat->device_id, $job->device_id);
        $this->assertDatabaseCount('repair_devices', 1);
        $this->get(route('documentation.show', 'receive-a-repair'))->assertOk();
        $this->get(route('repairs.settings'))->assertOk();
        $role = Role::create(['name' => 'Repair tech', 'sales_visibility' => 'OWN']);
        $role->permissions()->attach(Permission::whereIn('name', ['repairs.view', 'repairs.edit', 'repairs.unlock'])->pluck('id'));
        $tech = User::factory()->create(['role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($tech)->get(route('repairs.index'))->assertOk()->assertDontSee($job->invoiceNumber());
        $this->get(route('repairs.show', $job))->assertForbidden();
        $this->postJson(route('repairs.unlock', $job))->assertForbidden();
        $this->get(route('repairs.reports'))->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin);
        $this->setJobStatus($job, 'DIAGNOSING', ['technician_id' => $tech->id]);
        $this->flushSession();
        $this->actingAs($tech)->get(route('repairs.show', $job))->assertOk();
        $this->get(route('repairs.show', $repeat))->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin);
        config(['services.phone_rep' => false]);
        $this->get(route('repairs.show', $job))->assertNotFound();
        $this->get(route('repairs.customers'))->assertNotFound();
        $this->get(route('repairs.track'))->assertNotFound();
        $this->get(route('documentation.show', 'receive-a-repair'))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Repair desk')->assertDontSee('Phone repairs');
        $this->assertFalse($this->admin->hasPermission('repairs.view'));
        $this->assertDatabaseCount('repair_jobs', 2);
        config(['features.super_admin' => true]);
        $super = SuperAdmin::create(['name' => 'Repair Super Admin', 'username' => 'repair-super', 'password' => 'password']);
        $this->actingAs($super, 'superadmin')->get(route('superadmin.repairs.settings'))->assertNotFound();
        $this->assertFalse($super->hasPermission('repairs.settings'));
        config(['services.phone_rep' => true]);
        $this->get(route('superadmin.repairs.settings'))->assertOk();
        $this->put(route('superadmin.repairs.settings.update'), ['terms' => 'Supervised intake'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Supervised intake', app(SettingsService::class)->get('repair_terms'));
    }

    public function test_cheque_hold_clearance_and_return_are_counted_once(): void
    {
        config(['features.cheque' => true]);
        $job = $this->intake();
        $this->setJobStatus($job, 'DIAGNOSING', ['labor' => 600]);
        $this->setJobStatus($job, 'READY');
        $this->setJobStatus($job, 'COMPLETED');
        $date = today()->toDateString();
        $data = $this->payment('500', 'CHEQUE') + ['cheque' => ['cheque_number' => 'REPAIR-01', 'bank_name' => 'Test Bank', 'cheque_date' => $date]];
        $this->post(route('repairs.payment', [$job, 'collect']), $data)->assertSessionHasNoErrors();
        $this->post(route('repairs.payment', [$job, 'collect']), $data)->assertSessionHasNoErrors();
        $held = Cheque::where('repair_job_id', $job->id)->firstOrFail();
        $this->assertDatabaseCount('cheques', 1);
        $this->assertSame('500.00', $job->chequeHold());
        $this->assertSame('600.00', $job->fresh()->due());
        $this->post(route('repairs.payment', [$job, 'collect']), $this->payment('200', 'CARD'))->assertSessionHasErrors('amount');
        $this->get(route('repairs.show', $job))->assertOk()->assertSee('Pending cheque');
        $this->post(route('cheques.action', [$held, 'pass']), ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $job->fresh()->due());
        $this->assertSame('0.00', $job->chequeHold());
        $this->assertSame('500.00', Money::round(app(ReportService::class)->build('payments', ['from' => $date, 'to' => $date])['cards']['Net collections']));
        $this->post(route('repairs.payment', [$job, 'collect']), $this->payment('100', 'CHEQUE') + [
            'cheque' => ['cheque_number' => 'REPAIR-02', 'bank_name' => 'Test Bank', 'cheque_date' => $date],
        ])->assertSessionHasNoErrors();
        $second = Cheque::where('cheque_number', 'REPAIR-02')->firstOrFail();
        $this->post(route('cheques.action', [$second, 'return']), ['confirmed' => 1, 'reason' => 'Dishonoured'])->assertSessionHasNoErrors();
        $this->assertSame('100.00', $job->fresh()->due());
        $this->assertSame('0.00', $job->chequeHold());
        config(['services.phone_rep' => false]);
        $this->get(route('cheques.show', $held))->assertNotFound();
        $this->assertDatabaseCount('cheques', 2);
    }

    public function test_repair_sms_requires_both_flags_per_event_switch_and_consent(): void
    {
        Http::preventStrayRequests();
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$repair, $sms]) {
            config(['services.phone_rep' => $repair, 'sms.enabled' => $sms, 'sms.username' => '94770000001', 'sms.password' => 'test-secret']);
            $this->get(route('documentation.show', 'configure-repair-sms'))->assertStatus($repair && $sms ? 200 : 404);
            $response = $this->get(route('settings.sms'))->assertStatus($sms ? 200 : 404);
            if ($repair && $sms) {
                $response->assertSee('Repair intake');
            } elseif ($sms) {
                $response->assertDontSee('Repair intake');
            }
        }
        $this->put(route('settings.sms.update'), ['username' => '94770000001', 'cheque_reminder_time' => '08:00', 'repair_intake_enabled' => 1, 'repair_status_enabled' => 1])->assertSessionHasNoErrors();
        $job = $this->intake(['sms_opt_in' => 1, 'unlock_type' => 'PASSWORD', 'device_unlock' => 'hidden-repair-password']);
        $this->intake();
        $this->assertDatabaseCount('sms_messages', 1);
        $this->setJobStatus($job, 'DIAGNOSING', ['sms_opt_in' => 0]);
        Http::fake(['textit.biz/*' => Http::response('OK:repair')]);
        $this->artisan('sms:send-pending')->assertSuccessful();
        Http::assertNothingSent();
        $message = SmsMessage::firstOrFail();
        $this->assertSame('CANCELLED', $message->status);
        $this->assertStringNotContainsString('hidden-repair-password', $message->message);
        config(['services.phone_rep' => false]);
        $this->get(route('sms.index'))->assertOk()->assertDontSee($job->tracking);
        $this->assertDatabaseCount('sms_messages', 1);

        config(['services.phone_rep' => true]);
        $this->put(route('settings.sms.update'), ['username' => '94770000001', 'cheque_reminder_time' => '08:00',
            'repair_intake_enabled' => 0, 'repair_ready_enabled' => 1, 'repair_delivery_enabled' => 1, 'repair_payment_enabled' => 1])->assertSessionHasNoErrors();
        $job = $this->intake(['sms_opt_in' => 1]);
        $this->setJobStatus($job, 'DIAGNOSING', ['labor' => 100]);
        $this->post(route('repairs.payment', [$job, 'collect']), $this->payment('100', 'CARD'))->assertSessionHasNoErrors();
        $this->setJobStatus($job, 'READY');
        $this->setJobStatus($job, 'COMPLETED');
        $this->setJobStatus($job, 'DELIVERED');
        $this->artisan('sms:send-pending')->assertSuccessful();
        Http::assertSentCount(5);
        $this->assertSame(['REPAIR_STATUS', 'REPAIR_PAYMENT', 'REPAIR_READY', 'REPAIR_STATUS', 'REPAIR_DELIVERY'],
            SmsMessage::where('status', 'SENT')->orderBy('id')->pluck('kind')->all());
    }

    public function test_catalog_selections_create_device_snapshots_and_archived_models_are_rejected(): void
    {
        $this->get(route('repairs.catalog'))->assertOk()->assertSee('Apple')->assertSee('Device catalog');
        $this->post(route('repairs.catalog.brands.store'), ['name' => 'Sony', 'active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $brand = RepairBrand::where('name', 'Sony')->firstOrFail();
        $this->post(route('repairs.catalog.models.store', $brand), ['name' => 'Xperia 10', 'inspection' => 'Fingerprint', 'active' => 1])->assertSessionHasNoErrors();
        $model = $brand->models()->firstOrFail();
        $this->post(route('repairs.catalog.models.store', $brand), ['name' => 'xperia 10', 'inspection' => 'Standard', 'active' => 1])->assertSessionHasErrors('name');

        $payload = [
            'customer_id' => $this->customer->id, 'submission' => (string) Str::uuid(), 'catalog_model_id' => $model->id,
            'reported_issues' => ['Cracked screen'], 'received_items' => ['Case'], 'condition_tags' => ['Scratches'],
            'received_at' => today()->toDateString(), 'warranty_days' => 0, 'priority' => 'NORMAL',
        ];
        $this->post(route('repairs.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $job = RepairJob::where('submission', $payload['submission'])->firstOrFail();
        $this->assertSame('Sony', $job->device->brand);
        $this->assertSame('Xperia 10', $job->device->model);
        $this->assertSame('Cracked screen', $job->faults);
        $this->assertSame('Case', $job->accessories);
        $this->assertSame('Scratches', $job->condition);

        $this->put(route('repairs.catalog.models.update', [$brand, $model]), ['name' => 'Xperia 10 V', 'inspection' => 'Fingerprint', 'active' => 0])->assertSessionHasNoErrors();
        $payload['submission'] = (string) Str::uuid();
        $this->post(route('repairs.store'), $payload)->assertSessionHasErrors('catalog_model_id');
        $this->assertSame('Xperia 10', $job->device->fresh()->model);
        $this->assertDatabaseCount('repair_jobs', 1);
    }

    public function test_receipt_defaults_and_repair_sms_switch_apply_without_changing_other_sms(): void
    {
        config(['sms.enabled' => true, 'sms.username' => '94770000001', 'sms.password' => 'test-secret']);
        $events = array_fill_keys(array_keys(app(SmsSettingsService::class)->repairTypes()), 0);
        $events['REPAIR_INTAKE'] = 1;
        $this->get(route('repairs.settings'))->assertOk()->assertSee('Enable repair SMS')->assertSee('Preview sample receipt');
        $this->put(route('repairs.settings.sms'), ['enabled' => 1, 'events' => $events])->assertSessionHasNoErrors();
        $job = $this->intake(['sms_opt_in' => 1, 'estimate' => '3500']);
        $this->assertDatabaseHas('sms_messages', ['kind' => 'REPAIR_INTAKE', 'status' => 'PENDING']);
        $this->put(route('repairs.settings.sms'), ['enabled' => 0, 'events' => $events])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sms_messages', ['kind' => 'REPAIR_INTAKE', 'status' => 'CANCELLED']);
        $this->intake(['sms_opt_in' => 1]);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertTrue(app(SmsSettingsService::class)->allows('RECEIPT'));

        $this->put(route('repairs.settings.update'), ['terms' => 'Bring your receipt', 'receipt' => [
            'size' => '80mm', 'layout' => 'compact', 'inspection' => 0, 'barcode' => 1, 'prices' => 1,
        ]])->assertSessionHasNoErrors();
        $this->get(route('repairs.print', [$job, 'intake']))->assertOk()->assertSee('size:80mm', false)
            ->assertSee('Bring your receipt')->assertSee('Tracking barcode')->assertSee('Estimate:')->assertDontSee('>Inspection<', false);
        $this->get(route('repairs.settings.preview', ['size' => 'A4', 'inspection' => 1, 'barcode' => 0]))->assertOk()
            ->assertSee('Sample receipt')->assertSee('size:A4', false)->assertSee('>Inspection<', false)->assertDontSee('Tracking barcode');
        $this->put(route('repairs.settings.preview'), ['terms' => 'Draft terms', 'receipt' => ['size' => 'A5']])
            ->assertOk()->assertSee('Draft terms')->assertSee('size:A5', false);
        $this->assertSame('Bring your receipt', app(SettingsService::class)->get('repair_terms'));
    }
}
