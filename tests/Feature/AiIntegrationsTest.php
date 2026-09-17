<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\UserRole;
use App\Livewire\AiIntegrations\Index;
use App\Models\AiIntegration;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->installation = Installation::query()->create([
            'name' => 'Test installation',
            'domain' => 'fast-landings.test',
            'origin_target' => 'origin.fast-landings.test',
        ]);
        $this->administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($this->administrator);
    }

    public function test_administrator_can_open_settings_from_the_profile_menu(): void
    {
        $this->get('http://fast-landings.test/admin')
            ->assertOk()->assertSee(route('ai-integrations.index'));
        $this->get(route('ai-integrations.index'))
            ->assertOk()->assertSee('AI integrations')->assertSee('No AI integrations yet');
        $this->get('http://customer.example.test/admin/ai-integrations')->assertNotFound();
    }

    public function test_multiple_integrations_including_the_same_provider_belong_to_the_instance(): void
    {
        $component = Livewire::test(Index::class);

        foreach (['Primary' => 'openai', 'Backup' => 'openai', 'Router' => 'openrouter'] as $name => $provider) {
            $component->set('name', $name)->set('provider', $provider)
                ->call('save', 'test-secret-'.$name)->assertHasNoErrors()
                ->assertSee($name)->assertSet('editingIntegrationId', null);
        }

        $this->assertSame(3, $this->installation->aiIntegrations()->count());
        $this->assertSame(2, $this->installation->aiIntegrations()->where('provider', 'openai')->count());
        $this->assertNull(AiIntegration::query()->first()->api_url);

        $anotherAdministrator = User::factory()->create(['role' => UserRole::Administrator]);
        Livewire::actingAs($anotherAdministrator)->test(Index::class)
            ->assertSee('Primary')->assertSee('Backup')->assertSee('Router');
        Http::assertNothingSent();
    }

    public function test_keys_are_encrypted_and_absent_from_serialization_html_and_livewire_responses(): void
    {
        $key = 'test-secret-that-must-not-be-returned';
        $component = Livewire::test(Index::class)->set('name', 'Private credentials')
            ->call('save', $key)->assertHasNoErrors()->assertDontSee($key);
        $integration = AiIntegration::query()->sole();
        $encrypted = DB::table('ai_integrations')->value('api_key');

        $this->assertNotSame($key, $encrypted);
        $this->assertSame($key, Crypt::decryptString($encrypted));
        $this->assertSame($key, $integration->api_key);
        $this->assertArrayNotHasKey('api_key', $integration->toArray());
        $this->assertStringNotContainsString($key, $integration->toJson());
        $component->assertDontSee($encrypted);
        $this->assertStringNotContainsString($key, json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR));

        $component->call('edit', $integration->id)->assertSet('name', 'Private credentials')->assertDontSee($key);
        $this->assertStringNotContainsString($key, json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('apiKey', $component->snapshot['data']);

        $component->set('name', '')->call('save', $key)->assertHasErrors('name');
        $this->assertStringNotContainsString($key, json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR));
    }

    public function test_editing_preserves_a_blank_key_replaces_an_entered_key_and_clears_custom_url(): void
    {
        $integration = $this->integration();
        $encrypted = $integration->getRawOriginal('api_key');

        $component = Livewire::test(Index::class)->call('edit', $integration->id)
            ->set('name', ' Renamed account ')->set('apiUrl', ' https://proxy.example.test/v1/ ')
            ->call('save')->assertHasNoErrors();

        $integration->refresh();
        $this->assertSame('Renamed account', $integration->name);
        $this->assertSame('https://proxy.example.test/v1', $integration->api_url);
        $this->assertSame($encrypted, $integration->getRawOriginal('api_key'));

        $component->call('edit', $integration->id)->set('apiUrl', '')
            ->call('save', ' replacement-key ')->assertHasNoErrors();

        $integration->refresh();
        $this->assertSame('replacement-key', $integration->api_key);
        $this->assertNull($integration->api_url);
        $this->assertDatabaseCount('ai_integrations', 1);
    }

    public function test_changing_provider_requires_a_replacement_key(): void
    {
        $integration = $this->integration();
        $component = Livewire::test(Index::class)->call('edit', $integration->id)
            ->set('provider', AiProvider::OpenRouter->value)
            ->call('save')->assertHasErrors('apiKey');

        $this->assertSame(AiProvider::OpenAi, $integration->fresh()->provider);
        $component->call('save', 'router-key')->assertHasNoErrors();
        $this->assertSame(AiProvider::OpenRouter, $integration->fresh()->provider);
        $this->assertSame('router-key', $integration->fresh()->api_key);
    }

    public function test_other_provider_requires_a_url_and_accepts_a_local_http_endpoint(): void
    {
        $component = Livewire::test(Index::class)->set('name', 'Local provider')->set('provider', 'custom')
            ->call('save', 'local-key')->assertHasErrors('apiUrl');
        $this->assertDatabaseCount('ai_integrations', 0);

        $component->set('apiUrl', 'http://localhost:11434/v1')->call('save', 'local-key')->assertHasNoErrors();
        $this->assertSame('http://localhost:11434/v1', AiIntegration::query()->sole()->api_url);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected(array $settings, string $key, string $field): void
    {
        Livewire::test(Index::class)->set(array_merge(['name' => 'Test integration'], $settings))
            ->call('save', $key)->assertHasErrors($field);
        $this->assertDatabaseCount('ai_integrations', 0);
    }

    public static function invalidSettings(): array
    {
        return [
            'blank name' => [['name' => '  '], 'key', 'name'],
            'long name' => [['name' => str_repeat('a', 121)], 'key', 'name'],
            'unknown provider' => [['provider' => 'unknown'], 'key', 'provider'],
            'blank key' => [[], '  ', 'apiKey'],
            'long key' => [[], str_repeat('a', 4097), 'apiKey'],
            'key containing whitespace' => [[], "key\nsecret", 'apiKey'],
            'invalid URL' => [['apiUrl' => 'not-a-url'], 'key', 'apiUrl'],
            'relative URL' => [['apiUrl' => '/api/v1'], 'key', 'apiUrl'],
            'non-HTTP URL' => [['apiUrl' => 'ftp://example.test/api'], 'key', 'apiUrl'],
            'credentials in URL' => [['apiUrl' => 'https://user:secret@example.test/v1'], 'key', 'apiUrl'],
            'query in URL' => [['apiUrl' => 'https://example.test/v1?key=secret'], 'key', 'apiUrl'],
            'fragment in URL' => [['apiUrl' => 'https://example.test/v1#secret'], 'key', 'apiUrl'],
            'long URL' => [['apiUrl' => 'https://example.test/'.str_repeat('a', 2048)], 'key', 'apiUrl'],
        ];
    }

    public function test_all_named_providers_allow_the_default_url(): void
    {
        foreach (AiProvider::cases() as $provider) {
            if ($provider === AiProvider::Custom) {
                continue;
            }

            Livewire::test(Index::class)->set('name', $provider->label())->set('provider', $provider->value)
                ->call('save', 'provider-key')->assertHasNoErrors()->assertSee($provider->defaultApiUrl());
            $this->assertDatabaseHas('ai_integrations', ['provider' => $provider->value, 'api_url' => null]);
        }
    }

    public function test_cancel_and_delete_clear_the_editor_without_affecting_other_integrations(): void
    {
        $integration = $this->integration();
        $other = $this->integration('Backup');
        $component = Livewire::test(Index::class)->call('edit', $integration->id)
            ->set('name', 'Unsaved')->call('cancelEdit')->assertSet('editingIntegrationId', null)->assertSet('name', '');
        $this->assertSame('Primary', $integration->fresh()->name);

        $component->call('edit', $integration->id)->call('remove', $integration->id)
            ->assertHasNoErrors()->assertSet('editingIntegrationId', null)->assertSee('Backup');
        $this->assertModelMissing($integration);
        $this->assertModelExists($other);
    }

    public function test_guests_editors_and_inactive_administrators_cannot_manage_integrations(): void
    {
        auth()->logout();
        $this->get(route('ai-integrations.index'))->assertRedirect(route('login'));
        Livewire::test(Index::class)->assertForbidden();

        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($editor)->get(route('ai-integrations.index'))->assertForbidden();
        $this->get('http://fast-landings.test/admin')->assertDontSee(route('ai-integrations.index'));
        Livewire::actingAs($editor)->test(Index::class)->assertForbidden();

        $inactive = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => false]);
        Livewire::actingAs($inactive)->test(Index::class)->assertForbidden();
        $this->actingAs($inactive)->get(route('ai-integrations.index'))->assertRedirect(route('login'));
    }

    #[DataProvider('restrictedActions')]
    public function test_revoked_access_blocks_actions_on_an_already_open_page(string $action, array $revocation): void
    {
        $integration = $this->integration();
        $component = Livewire::test(Index::class)->call('edit', $integration->id)->set('name', 'Changed');
        User::query()->whereKey($this->administrator->id)->update($revocation);

        $component->call($action, $action === 'save' ? 'replacement-key' : $integration->id)->assertForbidden();
        $this->assertSame('Primary', $integration->fresh()->name);
        $this->assertSame('saved-secret', $integration->fresh()->api_key);
    }

    public static function restrictedActions(): array
    {
        $cases = [];
        foreach (['edit', 'save', 'remove'] as $action) {
            $cases[$action.' after demotion'] = [$action, ['role' => UserRole::Editor->value]];
            $cases[$action.' after deactivation'] = [$action, ['is_active' => false]];
        }

        return $cases;
    }

    public function test_client_cannot_replace_the_editing_id(): void
    {
        $integration = $this->integration();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Index::class)->set('editingIntegrationId', $integration->id);
    }

    #[DataProvider('missingIntegrationActions')]
    public function test_missing_integration_cannot_be_edited_deleted_or_recreated_by_a_stale_form(string $action): void
    {
        $integration = $this->integration();
        $component = Livewire::test(Index::class)->call('edit', $integration->id);
        $integration->delete();

        $this->assertDatabaseCount('ai_integrations', 0);
        $this->expectException(ModelNotFoundException::class);
        $component->call($action, $action === 'save' ? 'key' : $integration->id);
    }

    public static function missingIntegrationActions(): array
    {
        return [['edit'], ['save'], ['remove']];
    }

    private function integration(string $name = 'Primary'): AiIntegration
    {
        return $this->installation->aiIntegrations()->create([
            'name' => $name,
            'provider' => AiProvider::OpenAi,
            'api_key' => 'saved-secret',
        ]);
    }
}
