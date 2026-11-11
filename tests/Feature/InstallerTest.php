<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Installer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/twininstall-test-'.Str::uuid();
        File::makeDirectory($this->directory, 0700);
        $this->app->useEnvironmentPath($this->directory);
        config([
            'installer.state_path' => $this->directory,
            'cache.stores.file.path' => $this->directory.'/cache',
            'cache.stores.file.lock_path' => $this->directory.'/cache',
            'session.files' => $this->directory.'/sessions',
        ]);
        File::makeDirectory($this->directory.'/sessions');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_fresh_install_renders_without_an_app_key_and_provisions_a_shop(): void
    {
        config(['app.key' => null]);
        $database = $this->useTemporaryDatabase();

        $this->get('/twininstall')->assertOk()->assertSee('Set up your POS')
            ->assertSee('Add sample products and stock')->assertDontSee('Installer PIN');
        $this->post('/twininstall', $this->payload())->assertOk()->assertSee('Twin POS is installed');

        $env = Dotenv::parse(File::get($this->directory.'/.env'));
        $this->assertSame('Mobile Test Shop', $env['APP_NAME']);
        $this->assertSame('true', $env['PHONE_REP']);
        $this->assertSame('true', $env['hr_module']);
        $this->assertSame('false', $env['EXPIRE']);
        $this->assertSame('false', $env['DEMO']);
        $this->assertSame($this->payload()['db_password'], $env['DB_PASSWORD']);
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertNotSame($env['APP_KEY'], config('app.key'));
        $this->assertTrue(is_file($this->directory.'/twininstall-installed'));

        $admin = $database->table('users')->sole();
        $this->assertSame('shop-owner', $admin->username);
        $this->assertTrue(Hash::check($this->payload()['password'], $admin->password));
        $this->assertSame('Mobile Test Shop', json_decode($database->table('settings')->where('key', 'business_name')->value('value')));
        $this->assertSame(6, $database->table('products')->count());
        $this->assertTrue($database->table('categories')->where('name', 'Mobile Accessories')->exists());
        $this->assertFalse($database->table('categories')->where('name', 'Groceries')->exists());
        $this->assertEquals(30, $database->table('products')->where('sku', 'DEMO-101')->value('stock'));
        $this->get('/twininstall')->assertOk()->assertSee('Installation locked')->assertDontSee('Database password');
    }

    #[DataProvider('shopProfiles')]
    public function test_recommended_setup_installs_the_matching_demo(
        string $shop,
        array $modules,
        string $category,
        string $sample,
        int $products,
    ): void {
        $database = $this->useTemporaryDatabase();
        $payload = array_replace($this->payload(), ['shop_type' => $shop, 'module_selection' => 'recommended']);
        // A stale browser selection must not override the selected shop preset.
        $this->post('/twininstall', $payload)->assertOk()->assertSee('Twin POS is installed');

        $env = Dotenv::parse(File::get($this->directory.'/.env'));
        $this->assertSame($shop, $env['TWIN_SHOP_TYPE']);
        foreach (array_keys(config('installer.modules')) as $flag) {
            $this->assertSame(in_array($flag, $modules, true) ? 'true' : 'false', $env[$flag], $flag);
        }
        $this->assertSame($products, $database->table('products')->count());
        $this->assertSame($products, $database->table('products')->where('stock', '>', 0)->count());
        $this->assertTrue($database->table('categories')->where('name', $category)->exists());
        $this->assertTrue($database->table('products')->where('name', $sample)->exists());
        $this->assertSame($shop === 'mobile' ? 1 : 0, $database->table('repair_jobs')->count());
        if ($shop === 'mobile') {
            $parts = $database->table('products')->where('available_for_repairs', true)->where('available_for_pos', false);
            $this->assertSame(2, $parts->count());
        }

        $datedBatches = $database->table('product_stock_layers')->whereNotNull('batch_number')->where('expires_at', '>', today());
        $this->assertSame(match ($shop) {
            'pharmacy' => 4,
            'supermarket' => 2,
            default => 0,
        }, $datedBatches->count());
    }

    public static function shopProfiles(): array
    {
        return [
            'supermarket' => ['supermarket', ['UNIT', 'BARCODE', 'EXPIRE'], 'Groceries', 'White Rice 1kg', 16],
            'phone shop' => ['mobile', ['UNIT', 'BARCODE', 'PHONE_REP'], 'Repair Parts', 'Nokia 105 Feature Phone', 6],
            'pharmacy' => ['pharmacy', ['UNIT', 'BARCODE', 'EXPIRE'], 'First Aid', 'Sterile Gauze Pack', 6],
            'general retail' => ['retail', ['UNIT', 'BARCODE'], 'Stationery', 'A5 Notebook', 5],
            'fancy shop' => ['fancy', ['UNIT', 'BARCODE', 'COLORS'], 'Jewellery', 'Traditional Nethi Chutti', 6],
            'electronics' => ['electronics', ['UNIT', 'BARCODE'], 'Storage', '32 GB USB Drive', 5],
            'restaurant' => ['restaurant', ['UNIT'], 'Meals', 'Vegetable Rice Bowl', 5],
        ];
    }

    public function test_custom_module_selection_can_disable_all_modules_and_skip_demo_stock(): void
    {
        $database = $this->useTemporaryDatabase();
        $data = array_replace($this->payload(), ['shop_type' => 'fancy', 'module_selection' => 'custom', 'sample_data' => '0']);
        unset($data['modules']);
        $this->post('/twininstall', $data)->assertOk();

        $env = Dotenv::parse(File::get($this->directory.'/.env'));
        $this->assertSame('false', $env['UNIT']);
        $this->assertSame('false', $env['BARCODE']);
        $this->assertSame('false', $env['COLORS']);
        $this->assertSame(0, $database->table('products')->count());
        $this->assertTrue($database->table('categories')->where('name', 'Jewellery')->exists());
    }

    public function test_setup_refuses_an_occupied_database_and_does_not_flash_credentials(): void
    {
        $database = $this->useTemporaryDatabase();
        $database->statement('create table keep_me (id integer primary key)');
        $database->table('keep_me')->insert(['id' => 42]);

        $this->from('/twininstall')->post('/twininstall', $this->payload())->assertRedirect('/twininstall')
            ->assertSessionHasErrors('db_database')->assertSessionMissing('_old_input.db_password')
            ->assertSessionMissing('_old_input.password');
        $this->assertFileDoesNotExist($this->directory.'/.env');
        $this->assertSame(42, $database->table('keep_me')->sole()->id);
        $this->assertFalse($database->getSchemaBuilder()->hasTable('migrations'));
    }

    public function test_locked_setup_requires_admin_and_the_configured_pin(): void
    {
        File::put($this->directory.'/.env', "APP_KEY=preserved-key\nDB_DATABASE=existing_db\nPHONE_REP=false\n");
        $this->seed();
        config(['installer.pin' => 'custom-check-73']);
        $changes = ['shop_type' => 'mobile', 'modules' => ['PHONE_REP'], 'installer_pin' => 'custom-check-73'];

        $this->post('/twininstall', $changes)->assertForbidden();
        $this->actingAs(User::firstOrFail());
        $this->get('/twininstall')->assertOk()->assertSee('Installer PIN')->assertDontSee($changes['installer_pin']);
        $this->from('/twininstall')->post('/twininstall', array_replace($changes, ['installer_pin' => 'wrong']))
            ->assertRedirect('/twininstall')->assertSessionHasErrors('installer_pin')->assertSessionMissing('_old_input.installer_pin');
        $this->post('/twininstall', $changes + ['db_database' => 'replacement_db'])->assertRedirect('/twininstall');
        $env = Dotenv::parse(File::get($this->directory.'/.env'));
        $this->assertSame('preserved-key', $env['APP_KEY']);
        $this->assertSame('existing_db', $env['DB_DATABASE']);
        $this->assertSame('true', $env['PHONE_REP']);
        $this->assertDatabaseCount('users', 1);
    }

    private function useTemporaryDatabase()
    {
        $database = $this->directory.'/installation.sqlite';
        touch($database);
        $config = array_replace(config('database.connections.sqlite'), ['database' => $database]);
        $this->app->instance(Installer::class, new class($config) extends Installer
        {
            public function __construct(private array $connection) {}

            protected function databaseConfig(#[\SensitiveParameter] array $data): array
            {
                return $this->connection;
            }
        });

        config(['database.connections.installer_assertions' => $config]);

        return DB::connection('installer_assertions');
    }

    private function payload(): array
    {
        return [
            'shop_type' => 'mobile', 'shop_name' => 'Mobile Test Shop', 'app_url' => 'https://pos.example.test',
            'modules' => ['UNIT', 'PHONE_REP', 'hr_module'], 'sample_data' => '1',
            'db_host' => 'localhost', 'db_port' => '3306', 'db_database' => 'example_db', 'db_username' => 'example',
            'db_password' => 'db "quote" \\ ${APP_NAME} #!',
            'admin_name' => 'Shop Owner', 'admin_email' => 'owner@example.test', 'admin_username' => 'shop-owner',
            'password' => 'Temporary-install-test-44', 'password_confirmation' => 'Temporary-install-test-44',
        ];
    }
}
