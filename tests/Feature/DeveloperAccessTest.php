<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\DeveloperAccess;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Permissions;
use App\Support\Resources;
use App\Support\Sidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeveloperAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['developer.username' => 'developer-test', 'developer.password' => 'developer-test-password']);
    }

    private function loginDeveloper(): void
    {
        $this->post(route('developer.authenticate'), ['username' => 'developer-test', 'password' => 'developer-test-password'])->assertRedirect(route('developer.controls'));
    }

    private function restrict(array $disabled): void
    {
        app(DeveloperAccess::class)->save(array_values(array_diff(array_keys(DeveloperAccess::catalog()), $disabled)));
    }

    public function test_developer_login_is_separate_and_portal_has_every_control(): void
    {
        $this->get(route('developer.controls'))->assertRedirect(route('developer.login'));
        $this->loginDeveloper();
        $this->assertGuest('web');
        $this->assertGuest('superadmin');
        $response = $this->get(route('developer.controls'))->assertOk()->assertSee('Choose what your store can use.');
        foreach (array_keys(DeveloperAccess::catalog()) as $key) {
            $response->assertSee('value="'.$key.'"', false);
        }
        $response->assertDontSee('developer-test-password');
        $this->post(route('developer.logout'))->assertRedirect(route('developer.login'));
        $this->get(route('developer.controls'))->assertRedirect(route('developer.login'));
    }

    public function test_store_administrators_cannot_change_developer_controls(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->putJson(route('developer.update'), ['enabled' => [], 'controls_complete' => '1'])->assertUnauthorized();
        $this->get(route('developer.controls'))->assertRedirect(route('developer.login'));
        $this->assertDatabaseMissing('settings', ['key' => 'developer_access']);
    }

    public function test_wrong_missing_and_throttled_credentials_never_authenticate(): void
    {
        config(['developer.password' => '']);
        $this->postJson(route('developer.authenticate'), ['username' => 'developer-test', 'password' => 'developer-test-password'])->assertUnprocessable();
        config(['developer.password' => 'developer-test-password']);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson(route('developer.authenticate'), ['username' => 'developer-test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson(route('developer.authenticate'), ['username' => 'developer-test', 'password' => 'developer-test-password'])->assertUnprocessable()->assertSee('Too many attempts');
        $this->get(route('developer.controls'))->assertRedirect(route('developer.login'));
    }

    public function test_credential_rotation_and_idle_expiry_invalidate_developer_sessions(): void
    {
        $this->loginDeveloper();
        config(['developer.password' => 'rotated-password']);
        $this->get(route('developer.controls'))->assertRedirect(route('developer.login'));
        config(['developer.password' => 'developer-test-password']);
        $this->loginDeveloper();
        $this->travel(61)->minutes();
        $this->get(route('developer.controls'))->assertRedirect(route('developer.login'));
    }

    public function test_main_module_controls_all_crud_and_retains_child_selections(): void
    {
        $this->loginDeveloper();
        $keys = array_keys(DeveloperAccess::catalog());
        $enabled = array_values(array_diff($keys, ['module:products', 'permission:products.delete']));
        $this->put(route('developer.update'), ['enabled' => $enabled, 'controls_complete' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);
        foreach (['view', 'create', 'edit', 'delete', 'manage_prices', 'view_history'] as $action) {
            $this->assertFalse($admin->hasPermission('products.'.$action));
            $this->assertFalse(Gate::forUser($admin)->allows('products.'.$action));
        }
        $this->get(route('manage.index', 'products'))->assertForbidden()->assertSee('Please contact the developer');
        $this->postJson(route('manage.store', 'products'), [])->assertForbidden()->assertJsonPath('developer.phone', config('app.developer.phone'));
        $this->putJson(route('manage.update', ['products', 999999]), [])->assertForbidden();
        $this->deleteJson(route('manage.destroy', ['products', 999999]))->assertForbidden();
        $this->get(route('manage.index', 'customers'))->assertOk();

        $this->put(route('developer.update'), ['enabled' => [...$enabled, 'module:products'], 'controls_complete' => '1'])->assertRedirect();
        $this->assertTrue($admin->hasPermission('products.create'));
        $this->assertFalse($admin->hasPermission('products.delete'));
        $this->get(route('manage.index', 'products'))->assertOk();
        $this->get(route('manage.create', 'products'))->assertOk();
    }

    public static function protectedRoutes(): array
    {
        return [
            'dashboard' => ['module:dashboard', 'GET', 'dashboard', []],
            'pos' => ['module:pos', 'GET', 'pos.index', []],
            'sales' => ['module:sales', 'GET', 'sales.index', []],
            'purchases' => ['module:purchases', 'POST', 'purchases.store', []],
            'register' => ['module:register', 'POST', 'register.open', []],
            'sales returns' => ['module:sales', 'GET', 'returns.create', ['kind' => 'sales']],
            'purchases returns' => ['module:purchases', 'GET', 'returns.index', ['kind' => 'purchase']],
            'reports' => ['module:reports', 'GET', 'reports.index', []],
            'report export' => ['module:reports', 'GET', 'reports.export', ['report' => 'sales']],
            'specific report pdf' => ['module:reports.stock', 'GET', 'reports.pdf', ['report' => 'stock']],
            'report pdf action' => ['permission:reports.sales.pdf', 'GET', 'reports.pdf', ['report' => 'sales']],
            'settings' => ['module:settings', 'GET', 'settings.index', []],
            'settings child' => ['module:settings', 'GET', 'manage.index', ['resource' => 'payment-methods']],
            'individual settings page' => ['module:settings.branding', 'GET', 'settings.edit', ['group' => 'branding']],
            'documentation' => ['module:documentation', 'GET', 'documentation.index', []],
            'migration' => ['module:migration', 'GET', 'migration.index', []],
            'cheques' => ['module:cheques', 'GET', 'cheques.index', []],
            'cheque tab' => ['module:cheques.returned', 'GET', 'cheques.tab', ['tab' => 'returned']],
            'barcode' => ['module:barcode', 'GET', 'barcode.index', []],
            'expiry' => ['module:reports', 'GET', 'expiry.index', []],
            'repairs' => ['module:repairs', 'GET', 'repairs.index', []],
            'repair report' => ['module:reports', 'GET', 'repairs.reports', []],
            'repair edit' => ['permission:repairs.edit', 'PUT', 'repairs.update', ['repair' => 999999]],
            'hr' => ['module:hr', 'GET', 'hr.index', []],
            'hr staff' => ['module:hr.staff', 'POST', 'hr.staff.store', []],
            'hr reports' => ['module:reports', 'GET', 'hr.reports.show', ['kind' => 'staff']],
            'sms' => ['module:sms', 'GET', 'sms.index', []],
            'users create' => ['permission:users.create', 'GET', 'manage.create', ['resource' => 'users']],
            'roles update' => ['permission:roles.edit', 'PUT', 'manage.update', ['resource' => 'roles', 'id' => 999999]],
            'sales edit' => ['permission:sales.edit', 'POST', 'sales.revise', ['sale' => 999999]],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_disabled_direct_links_and_actions_show_developer_contact(string $control, string $method, string $name, array $parameters): void
    {
        $this->restrict([$control]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->json($method, route($name, $parameters))->assertForbidden()
            ->assertJsonPath('message', 'Please contact the developer to get permission to use this feature.')
            ->assertJsonPath('developer.name', config('app.developer.name'))
            ->assertJsonPath('developer.phone', config('app.developer.phone'))
            ->assertJsonPath('developer.website', config('app.developer.web'));
    }

    public function test_dashboard_cards_reports_and_sidebar_links_follow_controls(): void
    {
        $this->restrict(['permission:dashboard.sales', 'module:reports', 'module:documentation', 'module:products', 'module:settings']);
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);
        $response = $this->get(route('dashboard'))->assertOk();
        foreach (['reports.index', 'documentation.index', 'settings.index'] as $name) {
            $response->assertDontSee('href="'.route($name).'"', false);
        }
        $response->assertDontSee('href="'.route('manage.index', 'products').'"', false);
        $this->assertFalse($admin->hasPermission('dashboard.sales'));
        $this->assertTrue($admin->hasPermission('dashboard.profit'));
        $this->assertSame([], ReportService::titles());
        $this->assertArrayNotHasKey('products.create', Permissions::visible());
        $sections = Sidebar::sections($admin);
        foreach ($sections as $groups) {
            foreach ($groups as $group) {
                foreach ($group['children'] as $link) {
                    $this->assertTrue($link['developer_allowed']);
                }
            }
        }
    }

    public function test_invalid_or_incomplete_submissions_do_not_change_access(): void
    {
        $this->loginDeveloper();
        $this->putJson(route('developer.update'), ['enabled' => ['unknown-key'], 'controls_complete' => '1'])->assertUnprocessable();
        $this->putJson(route('developer.update'), ['enabled' => []])->assertUnprocessable();
        $this->assertDatabaseMissing('settings', ['key' => 'developer_access']);
        $this->putJson(route('developer.update'), ['enabled' => [], 'controls_complete' => '1'])->assertRedirect();
        $this->get(route('developer.controls'))->assertOk();
        $this->assertFalse(app(DeveloperAccess::class)->moduleAllowed('dashboard'));
    }

    public function test_every_permission_has_a_module_and_developer_data_stays_out_of_store_settings(): void
    {
        foreach (DeveloperAccess::catalog() as $entry) {
            $this->assertNotNull($entry['module']);
        }
        $this->restrict(['module:reports']);
        $this->assertArrayNotHasKey('developer_access', app(SettingsService::class)->all());
        $this->assertNotNull(Setting::where('key', 'developer_access')->first());
    }

    public function test_developer_logout_does_not_sign_out_existing_store_session(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);
        $this->loginDeveloper();
        $this->post(route('developer.logout'))->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_every_resource_module_blocks_all_of_its_crud_routes(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        foreach (array_keys(Resources::all()) as $resource) {
            $this->restrict(['module:'.$resource]);
            foreach (['index', 'show', 'create', 'edit', 'store', 'update', 'destroy'] as $action) {
                $parameters = ['resource' => $resource];
                if (in_array($action, ['show', 'edit', 'update', 'destroy'])) {
                    $parameters['id'] = 999999;
                }
                $method = ['store' => 'POST', 'update' => 'PUT', 'destroy' => 'DELETE'][$action] ?? 'GET';
                $this->json($method, route('manage.'.$action, $parameters))->assertForbidden()
                    ->assertJsonPath('developer.name', config('app.developer.name'));
            }
        }
    }

    public function test_every_individual_report_blocks_its_page_pdf_and_csv(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        foreach (array_keys(ReportService::TITLES) as $report) {
            $this->restrict(['module:reports.'.$report]);
            foreach (['reports.show', 'reports.pdf', 'reports.export'] as $route) {
                $this->getJson(route($route, $report))->assertForbidden()
                    ->assertJsonPath('developer.phone', config('app.developer.phone'));
            }
        }
    }

    public function test_shared_settings_permissions_do_not_reveal_disabled_page_links(): void
    {
        config(['features.colors' => true, 'features.customer_tax' => true]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->restrict(['module:settings.branding', 'module:settings.customer_tax']);
        $this->get(route('settings.index'))->assertOk()
            ->assertDontSee('href="'.route('settings.edit', 'branding').'"', false)
            ->assertDontSee('href="'.route('settings.edit', 'customer_tax').'"', false)
            ->assertSee('href="'.route('settings.edit', 'business').'"', false);
        $this->get(route('settings.edit', 'branding'))->assertForbidden()->assertSee('Please contact the developer');
        $this->get(route('settings.edit', 'business'))->assertOk();
    }

    public function test_individual_cheque_tab_and_low_stock_sidebar_links_can_be_hidden(): void
    {
        config(['features.cheque' => true]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->restrict(['module:cheques.returned', 'module:products.low_stock']);
        $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('href="'.route('cheques.tab', 'returned').'"', false)
            ->assertSee('href="'.route('cheques.tab', 'received').'"', false);
        $this->getJson(route('cheques.tab', 'returned'))->assertForbidden();
        $this->getJson(route('manage.index', ['resource' => 'products', 'low_stock' => 1]))->assertForbidden();
    }
}
