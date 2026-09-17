<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_login_authentication_is_exposed_on_the_panel_domain(): void
    {
        $this->get('http://fast-landings.test/admin/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('ui-mark--fastlandings', false)
            ->assertSee('Fast<span> Landings</span>', false)
            ->assertSee('favicon.svg', false);

        $this->get('http://fast-landings.test/register')->assertNotFound();
        $this->post('http://fast-landings.test/register')->assertNotFound();
        $this->get('http://fast-landings.test/forgot-password')->assertNotFound();
    }

    public function test_active_user_can_log_in_and_guest_is_redirected_to_login(): void
    {
        $user = User::factory()->create([
            'email' => 'editor@example.test',
            'password' => 'correct-password',
            'is_active' => true,
        ]);

        $this->get('http://fast-landings.test/admin')
            ->assertRedirect(route('login'));

        Livewire::test(Login::class)
            ->set('email', 'EDITOR@EXAMPLE.TEST')
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_log_in_and_an_existing_session_is_revoked(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'correct-password',
            'is_active' => false,
        ]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();

        $this->actingAs($user)
            ->get('http://fast-landings.test/admin')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_panel_routes_are_scoped_to_the_configured_panel_domain(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('http://fast-landings.test/admin')
            ->assertOk();

        $this->get('http://customer.example.test/admin')->assertNotFound();
        $this->get('http://customer.example.test/admin/login')->assertNotFound();
    }
}
