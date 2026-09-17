<?php

namespace App\Livewire\AiIntegrations;

use App\Enums\AiProvider;
use App\Enums\UserRole;
use App\Models\Installation;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use SensitiveParameter;

class Index extends Component
{
    #[Locked]
    public ?string $editingIntegrationId = null;

    public string $name = '';

    public string $provider = 'openai';

    #[Locked]
    public int $formVersion = 0;

    public string $apiUrl = '';

    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    public function edit(string $integrationId): void
    {
        $this->ensureAdministrator();
        $integration = Installation::singleton()->aiIntegrations()->findOrFail($integrationId);

        $this->cancelEdit();
        $this->editingIntegrationId = $integration->id;
        $this->name = $integration->name;
        $this->provider = $integration->provider->value;
        $this->apiUrl = $integration->api_url ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingIntegrationId', 'name', 'provider', 'apiUrl');
        $this->resetValidation();
        $this->formVersion++;
    }

    public function save(#[SensitiveParameter] string $apiKey = ''): void
    {
        $this->ensureAdministrator();
        $installation = Installation::singleton();
        $integration = $this->editingIntegrationId === null
            ? null
            : $installation->aiIntegrations()->findOrFail($this->editingIntegrationId);

        $this->name = trim($this->name);
        $this->apiUrl = trim($this->apiUrl);

        // Accept the key only as an action argument, never as public component state.
        $validated = validator([
            'name' => $this->name,
            'provider' => $this->provider,
            'apiKey' => trim($apiKey),
            'apiUrl' => $this->apiUrl,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::enum(AiProvider::class)],
            'apiKey' => [
                Rule::requiredIf($integration === null || $integration->provider->value !== $this->provider),
                'nullable', 'string', 'max:4096', 'regex:/^\S+$/u',
            ],
            'apiUrl' => [
                'bail', Rule::requiredIf($this->provider === AiProvider::Custom->value),
                'nullable', 'string', 'max:2048', 'url:http,https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parts = parse_url($value);
                    if ($parts === false || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))) {
                        $fail('Enter a base URL without credentials, query parameters, or a fragment.');
                    }
                },
            ],
        ], [
            'apiKey.required' => 'Enter an API key. Changing the provider requires a new key.',
            'apiKey.regex' => 'The API key must not contain whitespace.',
            'apiUrl.required' => 'Enter the API URL for this provider.',
        ], [
            'apiKey' => 'API key',
            'apiUrl' => 'API URL',
        ])->validate();

        $attributes = [
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'api_url' => $validated['apiUrl'] !== '' ? rtrim($validated['apiUrl'], '/') : null,
        ];
        if ($validated['apiKey'] !== '') {
            $attributes['api_key'] = $validated['apiKey'];
        }

        if ($integration) {
            $integration->update($attributes);
        } else {
            $installation->aiIntegrations()->create($attributes);
        }

        $this->cancelEdit();
        session()->flash('saved', $integration ? 'AI integration updated.' : 'AI integration added.');
    }

    public function remove(string $integrationId): void
    {
        $this->ensureAdministrator();
        Installation::singleton()->aiIntegrations()->findOrFail($integrationId)->delete();

        if ($this->editingIntegrationId === $integrationId) {
            $this->cancelEdit();
        }

        session()->flash('saved', 'AI integration removed.');
    }

    public function render()
    {
        $this->ensureAdministrator();

        return view('livewire.ai-integrations.index', [
            'integrations' => Installation::singleton()->aiIntegrations()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'provider', 'api_url']),
            'providers' => AiProvider::cases(),
            'selectedProvider' => AiProvider::tryFrom($this->provider),
        ])->layout('components.layouts.app', ['title' => 'AI integrations · Fast Landings']);
    }

    private function ensureAdministrator(): void
    {
        abort_unless(User::query()->whereKey(auth()->id())
            ->where('role', UserRole::Administrator->value)
            ->where('is_active', true)->exists(), 403);
    }
}
