<?php

namespace App\Livewire\Landings;

use App\Livewire\Landings\Concerns\InteractsWithLandingTags;
use App\Models\Domain;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Services\DomainChecks;
use App\Services\DomainManager;
use App\Services\LandingArchiveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Show extends Component
{
    use InteractsWithLandingTags;
    use WithFileUploads;

    #[Locked]
    public string $landingId;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public bool $isActive = true;

    public string $domainId = '';

    public string $transferDomainId = '';

    public string $baseDomainId = '';

    public string $subdomain = '';

    #[Locked]
    public array $domainTransfer = [];

    public $archive;

    public function mount(Landing $landing): void
    {
        $this->landingId = $landing->id;
        $this->fillFrom($landing);
    }

    public function save(): void
    {
        $landing = $this->landing();
        $this->name = trim($this->name);
        $this->slug = Str::lower(trim($this->slug));
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', Rule::unique('landings', 'slug')->ignore($landing->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['boolean'],
        ]);

        $tags = $this->validateTags();

        DB::transaction(function () use ($landing, $validated, $tags): void {
            $landing->update([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?: null,
                'is_active' => $validated['isActive'],
            ]);
            $landing->syncTags($tags);
        });
        session()->flash('saved', 'Landing settings saved.');
    }

    public function deploy(LandingArchiveService $archives): void
    {
        $this->validate(['archive' => ['required', 'file', 'mimes:zip', 'max:'.config('fast-landings.max_upload_kb')]]);
        $archives->deploy($this->landing(), $this->archive, auth()->user());
        $this->reset('archive');
        session()->flash('saved', 'New release deployed and activated.');
    }

    public function activate(string $releaseId, LandingArchiveService $archives): void
    {
        $release = LandingRelease::query()->where('landing_id', $this->landingId)->findOrFail($releaseId);
        $archives->activate($this->landing(), $release);
        session()->flash('saved', 'Release activated.');
    }

    public function deleteRelease(string $releaseId, LandingArchiveService $archives): void
    {
        $release = LandingRelease::query()->where('landing_id', $this->landingId)->findOrFail($releaseId);
        $archives->delete($release);
        session()->flash('saved', 'Release deleted.');
    }

    public function assignDomain(DomainManager $domains): void
    {
        $this->validate(['domainId' => ['required', 'string', Rule::exists('domains', 'id')]]);
        $domain = Domain::query()->with('landing')->findOrFail($this->domainId);

        if ($domain->landing_id !== null) {
            $this->reviewTransfer($domain);

            return;
        }

        $domains->reassign($domain, $this->landing(), expectedLandingId: null);
        $this->reset('domainId', 'transferDomainId', 'domainTransfer');
        session()->flash('saved', 'Domain assigned. DNS settings are unchanged.');
    }

    public function reviewDomainTransfer(): void
    {
        $this->validate(['transferDomainId' => ['required', 'string', Rule::exists('domains', 'id')]]);
        $this->reviewTransfer(Domain::query()->with('landing')->findOrFail($this->transferDomainId));
    }

    public function assignSubdomain(DomainManager $domains, DomainChecks $checks): void
    {
        $this->subdomain = Str::lower(trim($this->subdomain));
        $this->validate([
            'baseDomainId' => ['required', 'string', Rule::exists('domains', 'id')->where('dns_scope', 'wildcard')->whereNull('parent_domain_id')],
            'subdomain' => ['required', 'string', 'max:63'],
        ]);

        $base = Domain::query()->findOrFail($this->baseDomainId);
        $domain = $domains->createSubdomain($base, $this->landing(), $this->subdomain);
        $this->reset('subdomain');

        try {
            $checks->requestCheck($domain);
            session()->flash('saved', $domain->hostname.' assigned to this landing. Its DNS check will run in the background.');
        } catch (Throwable $exception) {
            report($exception);
            session()->flash('saved', $domain->hostname.' saved and assigned, but its DNS check could not be queued. Check your queue worker and retry from Connection details.');
        }
    }

    public function cancelDomainTransfer(): void
    {
        $this->reset('domainTransfer');
        $this->resetValidation('assignmentId');
    }

    public function confirmDomainTransfer(DomainManager $domains): void
    {
        if ($this->domainTransfer === []) {
            throw ValidationException::withMessages(['assignmentId' => 'Choose a domain and review its current landing before moving it.']);
        }

        $domain = Domain::query()->find($this->domainTransfer['domain_id']);
        if ($domain === null) {
            $this->reset('domainTransfer');
            throw ValidationException::withMessages(['assignmentId' => 'This domain was removed. Choose another domain.']);
        }

        try {
            $domains->reassign($domain, $this->landing(), expectedLandingId: $this->domainTransfer['from_id']);
        } catch (ValidationException $exception) {
            $this->reset('domainTransfer');

            throw $exception;
        }

        $this->reset('domainId', 'transferDomainId', 'domainTransfer');
        session()->flash('saved', 'Domain moved to this landing. DNS settings are unchanged.');
    }

    public function detachDomain(string $domainId, DomainManager $domains): void
    {
        $domain = Domain::query()->where('landing_id', $this->landingId)->findOrFail($domainId);
        $domains->reassign($domain, null, expectedLandingId: $this->landingId);
        session()->flash('saved', 'Domain unassigned. It no longer serves this landing; its DNS settings are unchanged.');
    }

    public function makePrimary(string $domainId, DomainManager $domains): void
    {
        $domain = Domain::query()->where('landing_id', $this->landingId)->findOrFail($domainId);
        $domains->reassign($domain, $this->landing(), makePrimary: true, expectedLandingId: $this->landingId);
        session()->flash('saved', 'Primary domain updated. Open landing will use this address. No redirects were added.');
    }

    public function deleteLanding(): void
    {
        $landing = $this->landing();
        $path = $landing->id;

        DB::transaction(function () use ($landing): void {
            $locked = Landing::query()->lockForUpdate()->findOrFail($landing->id);
            Domain::query()->where('landing_id', $locked->id)->update(['is_primary' => false]);
            $locked->delete();
        }, 3);

        Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($path);
        session()->flash('saved', 'Landing deleted. Its domains are now unassigned.');
        $this->redirectRoute('landings.index', navigate: true);
    }

    public function render()
    {
        $landing = $this->landing()->load(['template', 'activeRelease', 'domains.parentDomain', 'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('hostname')]);
        $wildcardDomains = Domain::query()
            ->with('cloudflareDomain.zone.account.integration')
            ->where('dns_scope', 'wildcard')
            ->whereNull('parent_domain_id')
            ->orderBy('hostname')
            ->get();

        return view('livewire.landings.show', [
            'landing' => $landing,
            'tagSuggestions' => $this->tagSuggestions(),
            'releases' => $landing->releases()->with(['uploader', 'template'])->latest()->orderByDesc('id')->get(),
            'availableDomains' => Domain::query()->whereNull('landing_id')->orderBy('hostname')->get(),
            'assignedDomains' => Domain::query()->with('landing')->whereNotNull('landing_id')->where('landing_id', '!=', $landing->id)->orderBy('hostname')->get(),
            'wildcardDomains' => $wildcardDomains,
            'selectedBaseDomain' => $wildcardDomains->firstWhere('id', $this->baseDomainId),
        ])->layout('components.layouts.app', ['title' => $landing->name.' · Fast Landings']);
    }

    private function landing(): Landing
    {
        return Landing::query()->findOrFail($this->landingId);
    }

    private function reviewTransfer(Domain $domain): void
    {
        $this->resetValidation('assignmentId');

        if ($domain->landing_id === $this->landingId) {
            throw ValidationException::withMessages(['assignmentId' => 'This domain is already assigned to this landing.']);
        }

        if ($domain->landing_id === null) {
            throw ValidationException::withMessages(['assignmentId' => 'This domain is now unassigned. Choose it from Available domains.']);
        }

        $this->domainTransfer = [
            'domain_id' => $domain->id,
            'hostname' => $domain->hostname,
            'from_id' => $domain->landing_id,
            'from_name' => $domain->landing->name,
            'to_name' => $this->landing()->name,
        ];
    }

    private function fillFrom(Landing $landing): void
    {
        $this->tags = $landing->tags->pluck('name')->all();
        $this->name = $landing->name;
        $this->slug = $landing->slug;
        $this->description = (string) $landing->description;
        $this->isActive = $landing->is_active;
    }
}
