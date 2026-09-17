<?php

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Livewire\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
        $this->actingAs($this->administrator);
    }

    public function test_administrator_can_list_users(): void
    {
        $editor = User::factory()->create([
            'name' => 'Landing Editor',
            'email' => 'editor@example.test',
            'role' => UserRole::Editor,
        ]);

        Livewire::test(Index::class)
            ->assertSee($this->administrator->email)
            ->assertSee($editor->name)
            ->assertSee($editor->email);
    }

    public function test_administrator_can_create_editors_and_administrators(): void
    {
        $component = Livewire::test(Index::class)
            ->set('name', 'First Editor')
            ->set('email', 'EDITOR@EXAMPLE.TEST')
            ->set('password', 'editor-password')
            ->set('password_confirmation', 'editor-password')
            ->set('role', UserRole::Editor->value)
            ->call('createUser')
            ->assertHasNoErrors();

        $editor = User::query()->where('email', 'editor@example.test')->sole();
        $this->assertSame(UserRole::Editor, $editor->role);
        $this->assertTrue($editor->is_active);
        $this->assertTrue(Hash::check('editor-password', $editor->password));

        $component
            ->set('name', 'Second Administrator')
            ->set('email', 'admin-2@example.test')
            ->set('password', 'administrator-password')
            ->set('password_confirmation', 'administrator-password')
            ->set('role', UserRole::Administrator->value)
            ->call('createUser')
            ->assertHasNoErrors();

        $administrator = User::query()->where('email', 'admin-2@example.test')->sole();
        $this->assertSame(UserRole::Administrator, $administrator->role);
        $this->assertTrue($administrator->is_active);
    }

    public function test_administrator_can_toggle_another_users_access(): void
    {
        $editor = User::factory()->create([
            'role' => UserRole::Editor,
            'is_active' => true,
        ]);

        $component = Livewire::test(Index::class)
            ->call('toggleActive', $editor->id)
            ->assertHasNoErrors();
        $this->assertFalse($editor->fresh()->is_active);

        $component->call('toggleActive', $editor->id)->assertHasNoErrors();
        $this->assertTrue($editor->fresh()->is_active);
    }

    public function test_administrator_can_change_another_users_role(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Editor,
            'is_active' => true,
        ]);

        $component = Livewire::test(Index::class)
            ->call('changeRole', $user->id, UserRole::Administrator->value)
            ->assertHasNoErrors();
        $this->assertSame(UserRole::Administrator, $user->fresh()->role);

        $component
            ->call('changeRole', $user->id, UserRole::Editor->value)
            ->assertHasNoErrors();
        $this->assertSame(UserRole::Editor, $user->fresh()->role);
    }

    public function test_administrator_can_set_another_users_password(): void
    {
        $editor = User::factory()->create([
            'role' => UserRole::Editor,
            'remember_token' => 'previous-remember-token',
        ]);
        $otherUser = User::factory()->create(['role' => UserRole::Editor]);
        DB::table('sessions')->insert([
            [
                'id' => 'editor-session',
                'user_id' => $editor->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'other-session',
                'user_id' => $otherUser->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ],
        ]);

        Livewire::test(Index::class)
            ->set("passwordForms.{$editor->id}.password", 'replacement-password')
            ->set("passwordForms.{$editor->id}.password_confirmation", 'replacement-password')
            ->call('setPassword', $editor->id)
            ->assertHasNoErrors();

        $editor->refresh();
        $this->assertTrue(Hash::check('replacement-password', $editor->password));
        $this->assertNotSame('previous-remember-token', $editor->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'editor-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    public function test_administrator_cannot_deactivate_self_even_when_another_administrator_exists(): void
    {
        User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);

        Livewire::test(Index::class)
            ->call('toggleActive', $this->administrator->id)
            ->assertHasErrors('users');

        $this->assertTrue($this->administrator->fresh()->is_active);
    }

    public function test_administrator_cannot_demote_self_even_when_another_administrator_exists(): void
    {
        User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);

        Livewire::test(Index::class)
            ->call('changeRole', $this->administrator->id, UserRole::Editor->value)
            ->assertHasErrors('users');

        $this->assertSame(UserRole::Administrator, $this->administrator->fresh()->role);
    }

    public function test_final_active_administrator_cannot_be_deactivated_or_demoted(): void
    {
        User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => false,
        ]);

        Livewire::test(Index::class)
            ->call('toggleActive', $this->administrator->id)
            ->assertHasErrors('users');

        Livewire::test(Index::class)
            ->call('changeRole', $this->administrator->id, UserRole::Editor->value)
            ->assertHasErrors('users');

        $administrator = $this->administrator->fresh();
        $this->assertTrue($administrator->is_active);
        $this->assertSame(UserRole::Administrator, $administrator->role);
    }

    public function test_editor_cannot_mount_or_mutate_user_management(): void
    {
        $editor = User::factory()->create([
            'role' => UserRole::Editor,
            'is_active' => true,
        ]);

        Livewire::actingAs($editor)
            ->test(Index::class)
            ->assertForbidden();
    }

    public function test_revoked_administrator_cannot_submit_an_already_mounted_mutation(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $component = Livewire::test(Index::class);

        User::query()->whereKey($this->administrator)->update([
            'role' => UserRole::Editor->value,
        ]);

        $component->call('toggleActive', $editor->id)->assertForbidden();
        $this->assertTrue($editor->fresh()->is_active);
    }
}
