<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MigrationWebTest extends TestCase
{
    public function test_migration_page_renders_with_status(): void
    {
        $response = $this->get('/migration');

        $response->assertOk();
        $response->assertSee('Database Migration');
        $response->assertSee('Database Connection');
        $response->assertSee('Migration Summary');
        $response->assertSee('Run Migrations');
        $response->assertSee('Clear All Cache');
    }

    public function test_redirects_from_migrate_and_migrations(): void
    {
        $this->get('/migrate')->assertRedirect('/migration');
        $this->get('/migrations')->assertRedirect('/migration');
    }

    public function test_run_migrations_via_post_executes(): void
    {
        $response = $this->post('/migration/run');

        $response->assertRedirect('/migration');
        $response->assertSessionHas('success', 'Migrations executed successfully!');
        $response->assertSessionHas('console_output');
    }

    public function test_clear_cache_via_post_executes(): void
    {
        $response = $this->post('/migration/cache');

        $response->assertRedirect('/migration');
        $response->assertSessionHas('success', 'Application cache cleared successfully!');
        $response->assertSessionHas('console_output');
    }

    public function test_storage_link_via_post_executes(): void
    {
        $response = $this->post('/migration/storage-link');

        $response->assertRedirect('/migration');
        $response->assertSessionHas('success', 'Storage link command completed successfully!');
        $response->assertSessionHas('console_output');
    }

    public function test_json_requests_return_json(): void
    {
        $response = $this->postJson('/migration/cache');

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Application cache cleared.',
        ]);
    }

    public function test_pin_protection_blocks_unauthorized_when_pin_configured(): void
    {
        putenv('MIGRATION_PIN=secure-pin-999');

        try {
            $response = $this->get('/migration');
            $response->assertOk();
            $response->assertSee('Authentication Required');
            $response->assertDontSee('Run Migrations');

            // Trying to run migration while locked redirects with error
            $runResponse = $this->post('/migration/run');
            $runResponse->assertRedirect('/migration');
            $runResponse->assertSessionHas('error');

            // Unlocking with wrong pin fails
            $failUnlock = $this->post('/migration/unlock', ['pin' => 'wrong-pin']);
            $failUnlock->assertRedirect('/migration');
            $failUnlock->assertSessionHas('error', 'Incorrect PIN or administrator credentials.');

            // Unlocking with correct pin succeeds
            $successUnlock = $this->post('/migration/unlock', ['pin' => 'secure-pin-999']);
            $successUnlock->assertRedirect('/migration');
            $successUnlock->assertSessionHas('success', 'Access granted.');

            // Now page is unlocked
            $this->get('/migration')->assertOk()->assertSee('Run Migrations');

            // Lock works
            $this->post('/migration/lock')->assertRedirect('/migration');
            $this->get('/migration')->assertOk()->assertSee('Authentication Required');

            // Accessing with ?key= in query string unlocks
            $this->get('/migration?key=secure-pin-999')->assertOk()->assertSee('Run Migrations');
        } finally {
            putenv('MIGRATION_PIN');
        }
    }

    public function test_administrator_bypasses_pin_requirement(): void
    {
        $this->artisan('migrate');
        putenv('MIGRATION_PIN=secret-1234');

        try {
            $adminRole = Role::firstOrCreate(
                ['name' => 'Administrator'],
                ['display_name' => 'Administrator', 'description' => 'Admin', 'system' => true]
            );
            $adminRole->update(['system' => true]);

            $admin = User::factory()->create([
                'role_id' => $adminRole->id,
                'active' => true,
            ]);

            $this->actingAs($admin)
                ->get('/migration')
                ->assertOk()
                ->assertSee('Run Migrations')
                ->assertDontSee('Authentication Required');
        } finally {
            putenv('MIGRATION_PIN');
        }
    }
}
