<?php

namespace App\Livewire\Landings;

use App\Livewire\Landings\Concerns\InteractsWithLandingTags;
use App\Models\Landing;
use App\Services\LandingArchiveService;
use App\Services\LandingPreviewService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Spatie\Tags\Tag;
use Throwable;

class Index extends Component
{
    use InteractsWithLandingTags;
    use WithFileUploads;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $tag = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showCreate = false;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public $archive;

    public function mount(): void
    {
        $this->showCreate = request()->boolean('create');
        app(LandingPreviewService::class)->requestMissing();
    }

    public function updatedName(): void
    {
        if ($this->slug === '') {
            $this->slug = Str::slug($this->name);
        }
    }

    public function create(LandingArchiveService $archives): void
    {
        $this->name = trim($this->name);
        $this->slug = Str::lower(trim($this->slug));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', Rule::unique('landings', 'slug')],
            'description' => ['nullable', 'string', 'max:2000'],
            'archive' => ['required', 'file', 'mimes:zip', 'max:'.config('fast-landings.max_upload_kb')],
        ]);

        $tags = $this->validateTags();

        $landing = Landing::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?: null,
            'is_active' => true,
        ]);

        try {
            $archives->deploy($landing, $this->archive, auth()->user());
            $landing->syncTags($tags);
        } catch (Throwable $exception) {
            $landing->delete();
            throw $exception;
        }

        session()->flash('saved', 'Landing deployed. Add a system or custom domain to publish it.');
        $this->redirectRoute('landings.show', $landing, navigate: true);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTag(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'tag', 'status');
        $this->resetPage();
    }

    public function refreshPreview(string $landingId, LandingPreviewService $previews): void
    {
        $release = Landing::query()->findOrFail($landingId)->activeRelease;
        abort_unless($release, 404);
        $previews->request($release, force: true);
    }

    public function render()
    {
        $search = mb_substr(trim($this->search), 0, 120);
        $landings = Landing::query()->withCount(['domains', 'releases'])
            ->with(['activeRelease', 'tags', 'template', 'domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('hostname')])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->whereLike('name', '%'.$search.'%')
                    ->orWhereLike('slug', '%'.$search.'%')
                    ->orWhereLike('description', '%'.$search.'%')
                    ->orWhereHas('tags', fn ($query) => $query->whereLike('name->'.app()->getLocale(), '%'.$search.'%'));
            }))
            ->when($this->tag !== '', fn ($query) => $query->whereHas('tags', fn ($query) => $query->where('tags.id', ctype_digit($this->tag) ? (int) $this->tag : 0)))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true)->has('activeRelease'))
            ->when($this->status === 'paused', fn ($query) => $query->where('is_active', false))
            ->when($this->status === 'empty', fn ($query) => $query->doesntHave('activeRelease'))
            ->latest()->orderByDesc('id')->paginate(12);

        return view('livewire.landings.index', [
            'landings' => $landings,
            'availableTags' => Tag::query()->whereNull('type')->get()->sortBy('name'),
            'tagSuggestions' => $this->tagSuggestions(),
            'hasPendingPreviews' => $landings->contains(fn ($landing) => in_array($landing->activeRelease?->preview_status, ['queued', 'processing'], true)),
        ])->layout('components.layouts.app', ['title' => 'Landings · Fast Landings']);
    }
}
