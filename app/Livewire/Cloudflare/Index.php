<?php

namespace App\Livewire\Cloudflare;

use App\Enums\UserRole;
use App\Models\Installation;
use App\Models\Landing;
use App\Models\User;
use App\Services\CloudflareConnections;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use SensitiveParameter;
use Throwable;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;

class Index extends Component
{
    #[Locked]
    public ?string $editingIntegrationId = null;

    #[Locked]
    public int $formVersion = 0;

    #[Locked]
    public string $landingId = '';

    public bool $showForm = false;

    public string $label = '';

    public function mount(): void
    {
        $this->ensureAdministrator();
        $landing = (string) request()->query('landing', '');
        $this->landingId = Landing::query()->whereKey($landing)->exists() ? $landing : '';
        $this->showForm = Installation::singleton()->cloudflareIntegrations()->doesntExist();
    }

    public function add(): void
    {
        $this->ensureAdministrator();
        $this->cancelEdit();
        $this->showForm = true;
    }

    public function edit(string $integrationId): void
    {
        $this->ensureAdministrator();
        $integration = Installation::singleton()->cloudflareIntegrations()->findOrFail($integrationId);
        $this->cancelEdit();
        $this->editingIntegrationId = $integration->id;
        $this->label = $integration->label ?? '';
        $this->showForm = true;
    }

    public function cancelEdit(): void
    {
        $this->ensureAdministrator();
        $this->reset('editingIntegrationId', 'label', 'showForm');
        $this->resetValidation();
        $this->formVersion++;
    }

    public function save(#[SensitiveParameter] string $apiToken = ''): void
    {
        $this->ensureAdministrator();
        $installation = Installation::singleton();
        if ($this->editingIntegrationId !== null) {
            $installation->cloudflareIntegrations()->findOrFail($this->editingIntegrationId);
        }

        $this->label = trim($this->label);
        $validated = validator(['label' => $this->label, 'apiToken' => trim($apiToken)], [
            'label' => [
                'required', 'string', 'max:120',
                Rule::unique('cloudflare_integrations', 'label')
                    ->where('owner_type', $installation->getMorphClass())->where('owner_id', $installation->id)
                    ->ignore($this->editingIntegrationId),
            ],
            'apiToken' => [Rule::requiredIf($this->editingIntegrationId === null), 'nullable', 'string', 'max:4096', 'regex:/^\S+$/u'],
        ], [
            'label.unique' => 'Use a different connection name so each saved token is easy to identify.',
            'apiToken.required' => 'Enter a Cloudflare API token.',
            'apiToken.regex' => 'The API token must not contain whitespace.',
        ], ['label' => 'connection name', 'apiToken' => 'API token'])->validate();

        $editing = $this->editingIntegrationId !== null;
        try {
            $connections = app(CloudflareConnections::class);
            if ($editing) {
                $connections->update($this->editingIntegrationId, $validated['label'], $validated['apiToken'] ?? '');
            } else {
                $connections->connect($validated['label'], $validated['apiToken']);
            }
        } catch (CloudflareException|LockTimeoutException $exception) {
            $this->addError('apiToken', $this->safeError($exception));
            $this->formVersion++;

            return;
        }

        $this->cancelEdit();
        session()->flash('saved', $editing ? 'Cloudflare connection updated. Existing domain links are preserved.' : 'Cloudflare connection added. Choose a zone below to add a domain.');
    }

    public function sync(string $integrationId): void
    {
        $this->ensureAdministrator();
        $this->resetValidation('connection.'.$integrationId);

        try {
            app(CloudflareConnections::class)->sync($integrationId);
            session()->flash('saved', 'Cloudflare accounts and zones refreshed.');
        } catch (CloudflareException|LockTimeoutException $exception) {
            $this->addError('connection.'.$integrationId, $this->safeError($exception));
        }
    }

    public function disconnect(string $integrationId): void
    {
        $this->ensureAdministrator();
        $this->resetValidation('connection.'.$integrationId);

        try {
            app(CloudflareConnections::class)->disconnect($integrationId);
        } catch (CloudflareException|LockTimeoutException $exception) {
            $this->addError('connection.'.$integrationId, $this->safeError($exception));

            return;
        }

        if ($this->editingIntegrationId === $integrationId) {
            $this->cancelEdit();
        }
        session()->flash('saved', 'Cloudflare connection disconnected. Your Cloudflare zones and DNS records were not changed.');
    }

    public function render()
    {
        $this->ensureAdministrator();

        return view('livewire.cloudflare.index', [
            'integrations' => Installation::singleton()->cloudflareIntegrations()
                ->with(['accounts' => fn ($query) => $query->orderBy('name'), 'accounts.zones' => fn ($query) => $query->withCount('domains')->orderBy('name')])
                ->orderBy('label')->orderBy('id')->get(),
        ])->layout('components.layouts.app', ['title' => 'Cloudflare connections · Fast Landings']);
    }

    private function ensureAdministrator(): void
    {
        abort_unless(User::query()->whereKey(auth()->id())->where('role', UserRole::Administrator->value)
            ->where('is_active', true)->exists(), 403);
    }

    private function safeError(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof CloudflareAuthenticationException => 'Cloudflare rejected the token. It may be expired or revoked. Edit the connection and enter an active token.',
            $exception instanceof CloudflarePermissionException => 'The token cannot access the required zones or DNS records. Check Zone → Zone → Read, Zone → DNS → Edit, and zone resources in Cloudflare.',
            $exception instanceof CloudflareRateLimitException => 'Cloudflare is limiting requests. Wait a minute, then try again.',
            $exception instanceof CloudflareTransportException => 'This server could not reach Cloudflare. Check its internet connection and try again.',
            $exception instanceof LockTimeoutException => 'A domain operation is using this connection. Wait a moment, then try again.',
            default => 'Cloudflare could not complete the request. Check the API token and its zone permissions, then try again.',
        };
    }
}
