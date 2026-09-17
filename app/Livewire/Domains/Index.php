<?php

namespace App\Livewire\Domains;

use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Services\DomainChecks;
use App\Services\DomainManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;
use TrafficOps\Cloudflare\Exceptions\CloudflareException;

class Index extends Component
{
    use WithPagination;

    public bool $showCreate = false;

    public string $mode = 'system';

    public string $dnsScope = 'exact';

    public string $baseHostname = '';

    public string $hostnameLabel = '';

    public string $landingId = '';

    public string $subdomain = '';

    public string $hostname = '';

    public string $integrationId = '';

    public string $zoneId = '';

    public string $zonesMessage = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $assignment = '';

    #[Url(except: '')]
    public string $provider = '';

    #[Url(as: 'domain', except: '')]
    public string $selectedDomainId = '';

    #[Locked]
    public string $returnLandingId = '';

    #[Locked]
    public ?string $assignmentFromId = null;

    public string $assignmentLandingId = '';

    public bool $showAssignment = false;

    /** @var array{domain: string, from: ?string, to: ?string}|null */
    #[Locked]
    public ?array $assignmentReview = null;

    public bool $showRemove = false;

    public bool $cleanupDns = false;

    public function mount(): void
    {
        $this->showCreate = request()->boolean('create');
        $requestedLanding = (string) request()->query('landing', '');
        $this->returnLandingId = Landing::query()->whereKey($requestedLanding)->exists() ? $requestedLanding : '';
        $this->landingId = $this->returnLandingId;
        if ($this->showCreate && in_array(request()->query('mode'), ['system', 'dns', 'cloudflare'], true)) {
            $this->mode = request()->query('mode');
        }
        if ($this->showCreate && in_array(request()->query('dnsScope'), ['exact', 'subdomain', 'wildcard'], true)) {
            $this->dnsScope = request()->query('dnsScope');
        }
        $requestedIntegration = (string) request()->query('integration', '');
        if ($this->mode === 'cloudflare' && Installation::singleton()->cloudflareIntegrations()->whereKey($requestedIntegration)->exists()) {
            $this->integrationId = $requestedIntegration;
            $zone = app(DomainManager::class)->zones($requestedIntegration)->firstWhere('id', request()->query('zone'));
            $this->zoneId = $zone?->id ?? '';
            $this->hostname = $zone?->name ?? '';
        }
    }

    public function startCreate(): void
    {
        $this->reset(['showAssignment', 'assignmentReview', 'showRemove']);
        $this->resetValidation();
        $this->showCreate = true;
        $this->dispatch('domain-create');
    }

    public function cancelCreate(): void
    {
        $this->reset(['showCreate', 'hostname', 'subdomain', 'zoneId', 'zonesMessage', 'baseHostname', 'hostnameLabel']);
        $this->resetValidation();
    }

    public function updatedMode(): void
    {
        $this->reset(['integrationId', 'zoneId', 'hostname', 'subdomain', 'zonesMessage', 'baseHostname', 'hostnameLabel']);
        $this->resetValidation();
    }

    public function updatedIntegrationId(): void
    {
        // The saved account catalogue is immediate; only an explicit refresh calls Cloudflare.
        $this->reset(['zoneId', 'hostname', 'zonesMessage']);
        $this->resetValidation();
    }

    public function updatedDnsScope(): void
    {
        $this->reset(['hostnameLabel', 'baseHostname']);
        $this->resetValidation();
        if ($this->mode === 'cloudflare') {
            $this->updatedZoneId();
        }
    }

    public function updatedZoneId(): void
    {
        $this->hostname = $this->hasSelectedIntegration()
            ? (app(DomainManager::class)->zones($this->integrationId)->firstWhere('id', $this->zoneId)?->name ?? '')
            : '';
        $this->resetValidation(['hostname', 'zoneId', 'domain']);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'assignment', 'provider'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'assignment', 'provider']);
        $this->resetPage();
    }

    public function filterOverview(string $filter): void
    {
        $this->clearFilters();
        if (in_array($filter, ['active', 'attention'], true)) {
            $this->status = $filter;
        } elseif ($filter === 'unassigned') {
            $this->assignment = 'unassigned';
        }
    }

    public function refetchCloudflareZones(): void
    {
        $this->reset('zonesMessage');
        $this->resetValidation('zones');
        if (! $this->hasSelectedIntegration()) {
            $this->addError('integrationId', 'Choose a connected Cloudflare connection.');

            return;
        }
        $domains = app(DomainManager::class);
        try {
            $domains->syncCloudflare($this->integrationId);
            $this->zonesMessage = 'Cloudflare zones refreshed.';
        } catch (CloudflareException|LockTimeoutException) {
            $this->addError('zones', 'Could not refresh Cloudflare zones. Check the token and its zone permissions, then try again.');
        }
        if ($this->zoneId !== '' && ! $domains->zones($this->integrationId)->contains('id', $this->zoneId)) {
            $this->reset(['zoneId', 'hostname']);
        }
    }

    public function create(DomainManager $domains, DomainChecks $checks): void
    {
        if ($this->mode !== 'system' && $this->dnsScope === 'subdomain') {
            $this->validate([
                'hostnameLabel' => ['required', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i'],
                'baseHostname' => [Rule::excludeIf($this->mode === 'cloudflare'), 'required', 'string', 'max:253'],
            ], ['hostnameLabel.regex' => 'Enter one subdomain label using letters, numbers, and hyphens.']);
            $base = $this->mode === 'cloudflare' && $this->hasSelectedIntegration()
                ? app(DomainManager::class)->zones($this->integrationId)->firstWhere('id', $this->zoneId)?->name
                : $this->baseHostname;
            $this->hostname = $this->hostnameLabel.'.'.trim((string) $base);
        }
        $this->validate([
            'dnsScope' => ['required', Rule::in(['exact', 'subdomain', 'wildcard'])],
            'mode' => ['required', Rule::in(['system', 'dns', 'cloudflare'])],
            'landingId' => ['nullable', Rule::exists('landings', 'id')],
            'subdomain' => [Rule::excludeIf($this->mode !== 'system'), 'required', 'string', 'max:253'],
            'hostname' => [Rule::excludeIf($this->mode === 'system'), Rule::requiredIf($this->mode === 'dns'), 'nullable', 'string', 'max:253'],
            'integrationId' => [Rule::excludeIf($this->mode !== 'cloudflare'), 'required', 'string'],
            'zoneId' => [Rule::excludeIf($this->mode !== 'cloudflare'), 'required', 'string'],
        ]);
        $landing = $this->landingId === '' ? null : Landing::query()->findOrFail($this->landingId);
        try {
            $domain = match ($this->mode) {
                'system' => $domains->createSystem($landing, $this->subdomain),
                'dns' => $domains->createDns($landing, $this->hostname, wildcard: $this->dnsScope === 'wildcard'),
                'cloudflare' => $domains->createCloudflare($landing, $this->hostname, $this->integrationId, $this->zoneId, wildcard: $this->dnsScope === 'wildcard'),
            };
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('domain', 'The domain could not be added. Please retry; if this continues, review the application logs.');

            return;
        }

        try {
            match ($domain->provider) {
                DomainProvider::Cloudflare => $checks->requestProvision($domain),
                DomainProvider::Dns => $checks->requestCheck($domain),
                DomainProvider::System => null,
            };
            session()->flash('saved', match ($domain->provider) {
                DomainProvider::Cloudflare => 'Domain added. Cloudflare DNS setup will continue in the background.',
                DomainProvider::Dns => 'Domain added. Follow the DNS instructions below; we will check automatically.',
                DomainProvider::System => 'System subdomain added. It uses your installation’s wildcard DNS.',
            });
        } catch (Throwable $exception) {
            report($exception);
            session()->flash('saved', 'Domain saved, but the background job could not be queued. Check your queue worker and retry from the domain details.');
        }
        $this->cancelCreate();
        $this->selectDomain($domain->id);
        $this->clearFilters();
    }

    public function selectDomain(string $domainId): void
    {
        Domain::query()->findOrFail($domainId);
        $this->selectedDomainId = $domainId;
        $this->dispatch('domain-selected');
        $this->reset(['showAssignment', 'assignmentReview', 'showRemove', 'cleanupDns']);
        $this->resetValidation();
    }

    public function closeDetails(): void
    {
        $this->reset(['selectedDomainId', 'showAssignment', 'assignmentReview', 'showRemove', 'cleanupDns']);
        $this->resetValidation();
    }

    public function verify(string $domainId, DomainChecks $checks): void
    {
        $domain = Domain::query()->findOrFail($domainId);
        $this->selectDomain($domainId);
        try {
            $checks->requestCheck($domain);
            session()->flash('saved', 'DNS check queued. The status will update automatically.');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('operation', 'Could not queue the check. Check the queue service on your server, then retry.');
        }
    }

    public function provision(string $domainId, DomainChecks $checks): void
    {
        $domain = Domain::query()->where('provider', DomainProvider::Cloudflare)->findOrFail($domainId);
        try {
            $checks->requestProvision($domain);
            session()->flash('saved', 'Cloudflare DNS setup queued. The status will update automatically.');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('operation', 'Could not queue DNS setup. Check the queue service on your server, then retry.');
        }
    }

    public function beginAssignment(string $domainId): void
    {
        $this->selectDomain($domainId);
        $domain = Domain::query()->findOrFail($domainId);
        $this->assignmentFromId = $domain->landing_id;
        $this->assignmentLandingId = (string) $domain->landing_id;
        $this->showAssignment = true;
    }

    public function reviewAssignment(): void
    {
        $this->validate(['assignmentLandingId' => ['nullable', Rule::exists('landings', 'id')]]);
        $domain = Domain::query()->findOrFail($this->selectedDomainId);
        if ($domain->landing_id !== $this->assignmentFromId) {
            $this->addError('assignmentId', 'The assignment changed in another session. Close this form and choose Change landing again.');

            return;
        }
        $target = $this->assignmentLandingId === '' ? null : $this->assignmentLandingId;
        if ($target === $this->assignmentFromId) {
            $this->addError('assignmentLandingId', 'Choose a different landing, or leave the domain unassigned.');

            return;
        }
        $this->assignmentReview = ['domain' => $domain->id, 'from' => $this->assignmentFromId, 'to' => $target];
    }

    public function backToAssignment(): void
    {
        $this->reset('assignmentReview');
        $this->resetValidation();
    }

    public function cancelAssignment(): void
    {
        $this->reset(['showAssignment', 'assignmentReview']);
        $this->resetValidation();
    }

    public function confirmAssignment(DomainManager $domains): void
    {
        if ($this->assignmentReview === null) {
            return;
        }
        $review = $this->assignmentReview;
        $domain = Domain::query()->findOrFail($review['domain']);
        $landing = $review['to'] === null ? null : Landing::query()->find($review['to']);
        if ($review['to'] !== null && $landing === null) {
            $this->addError('assignmentId', 'The selected landing no longer exists. Choose another landing.');

            return;
        }
        $domains->reassign($domain, $landing, expectedLandingId: $review['from']);
        $this->cancelAssignment();
        session()->flash('saved', $landing ? 'Domain assigned to '.$landing->name.'. DNS records were kept.' : 'Domain is now unassigned. DNS records were kept; this hostname no longer serves a landing.');
    }

    public function beginRemove(string $domainId): void
    {
        $this->selectDomain($domainId);
        $this->showRemove = true;
    }

    public function remove(DomainManager $domains): void
    {
        if (! $this->showRemove) {
            return;
        }
        $domain = Domain::query()->findOrFail($this->selectedDomainId);
        try {
            $domains->remove($domain, cleanupManagedRecords: $this->cleanupDns);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('operation', 'Could not remove the domain. Check the Cloudflare connection and retry, or turn off DNS cleanup to keep the records.');

            return;
        }
        $message = $this->cleanupDns
            ? 'Domain removed. Only DNS records created by Fast Landings were eligible for cleanup.'
            : 'Domain removed from Fast Landings. DNS records were kept.';
        $this->closeDetails();
        session()->flash('saved', $message);
    }

    public function render()
    {
        $installation = Installation::singleton();
        $integrations = $installation->cloudflareIntegrations()->with('accounts.zones')->orderBy('label')->get();
        $query = Domain::query()->with(['landing.activeRelease', 'cloudflareDomain.zone.account.integration', 'parentDomain.cloudflareDomain.zone.account.integration']);
        if (trim($this->search) !== '') {
            $search = '%'.mb_strtolower(trim($this->search)).'%';
            $query->where(fn ($query) => $query->whereRaw('LOWER(hostname) LIKE ?', [$search])
                ->orWhereHas('landing', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$search])));
        }
        if ($this->status === 'attention') {
            $query->whereIn('status', [DomainStatus::Drifted, DomainStatus::Error, DomainStatus::Unreachable]);
        } elseif ($this->status === 'pending') {
            $query->whereIn('status', [DomainStatus::Pending, DomainStatus::PendingPropagation]);
        } elseif ($this->status === 'active') {
            $query->where('status', DomainStatus::Active);
        }
        if ($this->assignment === 'unassigned') {
            $query->whereNull('landing_id');
        } elseif ($this->assignment === 'assigned') {
            $query->whereNotNull('landing_id');
        }
        if (DomainProvider::tryFrom($this->provider)) {
            $query->where('provider', $this->provider);
        }
        $counts = Domain::query()->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS connected, SUM(CASE WHEN landing_id IS NULL THEN 1 ELSE 0 END) AS unassigned, SUM(CASE WHEN status IN ('error', 'drifted', 'unreachable') THEN 1 ELSE 0 END) AS attention")->first();
        $selected = $this->selectedDomainId === '' ? null : Domain::query()
            ->with(['landing.activeRelease', 'cloudflareDomain.zone.account.integration', 'parentDomain.cloudflareDomain.zone.account.integration', 'cloudflareDomain.records'])
            ->find($this->selectedDomainId);

        return view('livewire.domains.index', [
            'installation' => $installation,
            'domains' => $query->orderBy('hostname')->paginate(15),
            'counts' => $counts,
            'selectedDomain' => $selected,
            'landings' => Landing::query()->with('activeRelease')->orderBy('name')->get(),
            'returnLanding' => $this->returnLandingId === '' ? null : Landing::query()->find($this->returnLandingId),
            'integrations' => $integrations,
            'selectedIntegration' => $integrations->firstWhere('id', $this->integrationId),
            'zones' => $this->mode === 'cloudflare' && $integrations->contains('id', $this->integrationId)
                ? app(DomainManager::class)->zones($this->integrationId) : collect(),
            'reviewFrom' => $this->assignmentReview ? Landing::query()->find($this->assignmentReview['from']) : null,
            'reviewTo' => $this->assignmentReview ? Landing::query()->find($this->assignmentReview['to']) : null,
        ])->layout('components.layouts.app', ['title' => 'Domains · Fast Landings']);
    }

    private function hasSelectedIntegration(): bool
    {
        return $this->integrationId !== '' && Installation::singleton()
            ->cloudflareIntegrations()->whereKey($this->integrationId)->exists();
    }
}
