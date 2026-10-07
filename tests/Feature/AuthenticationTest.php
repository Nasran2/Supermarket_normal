<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->account = User::create([
            'name' => 'Store Administrator',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => 'a-secure-test-password',
            'role_id' => Role::where('name', 'Administrator')->value('id'),
        ]);
    }

    public function test_username_and_password_can_sign_in(): void
    {
        $this->post('/login', ['login' => 'admin', 'password' => 'a-secure-test-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->account);
    }

    public function test_email_and_password_can_still_sign_in(): void
    {
        $this->post('/login', ['login' => 'admin@example.test', 'password' => 'a-secure-test-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->account);
    }

    public function test_login_normalizes_case_and_surrounding_spaces(): void
    {
        $this->post('/login', ['login' => '  ADMIN  ', 'password' => 'a-secure-test-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->account);
    }

    public function test_old_email_field_remains_compatible(): void
    {
        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'a-secure-test-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->account);
    }

    public function test_incorrect_password_and_display_name_cannot_sign_in(): void
    {
        $this->postJson('/login', ['login' => 'admin', 'password' => 'incorrect'])->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->postJson('/login', ['login' => $this->account->name, 'password' => 'a-secure-test-password'])->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_sign_in_using_either_identifier(): void
    {
        $this->account->update(['active' => false]);
        foreach (['admin', 'admin@example.test'] as $login) {
            $this->postJson('/login', ['login' => $login, 'password' => 'a-secure-test-password'])->assertUnprocessable()->assertJsonValidationErrors('login');
        }
        $this->assertGuest();
    }

    public function test_username_and_email_share_the_failed_attempt_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['login' => $i % 2 ? 'admin' : 'admin@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson('/login', ['login' => 'admin', 'password' => 'a-secure-test-password'])->assertUnprocessable()->assertSee('Too many attempts');
        $this->assertGuest();
    }

    public function test_login_requires_scalar_identifier_and_password(): void
    {
        $this->postJson('/login', ['login' => ['admin'], 'password' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors(['login', 'password']);
        $this->postJson('/login', [])->assertUnprocessable()->assertJsonValidationErrors(['login', 'password']);
        $this->assertGuest();
    }

    public function test_login_form_accepts_username_without_browser_email_validation(): void
    {
        $this->get('/login')->assertOk()->assertSee('Username or email')->assertSee('type="text" name="login"', false)->assertDontSee('type="email"', false);
    }

    public function test_user_management_saves_unique_normalized_username(): void
    {
        $this->actingAs($this->account);
        $data = ['name' => 'Cashier', 'username' => ' Cashier.One ', 'email' => 'cashier@example.test', 'password' => 'another-secure-password', 'role_id' => Role::where('name', 'Cashier')->value('id'), 'active' => true];
        $this->post(route('manage.store', 'users'), $data)->assertRedirect();
        $cashier = User::where('email', 'cashier@example.test')->firstOrFail();
        $this->assertSame('cashier.one', $cashier->username);
        $this->postJson(route('manage.store', 'users'), array_replace($data, ['username' => 'CASHIER.ONE', 'email' => 'other@example.test']))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->put(route('manage.update', ['users', $cashier->id]), array_replace($data, ['password' => '']))->assertRedirect();
        $this->assertSame('cashier.one', $cashier->fresh()->username);
    }

    public function test_usernames_cannot_look_like_emails_or_contain_spaces(): void
    {
        $this->actingAs($this->account);
        $data = ['name' => 'Cashier', 'email' => 'cashier@example.test', 'password' => 'another-secure-password', 'role_id' => Role::where('name', 'Cashier')->value('id'), 'active' => true];
        foreach (['someone@example.test', 'two words', str_repeat('a', 65)] as $username) {
            $this->postJson(route('manage.store', 'users'), $data + ['username' => $username])->assertUnprocessable()->assertJsonValidationErrors('username');
        }
    }
}
