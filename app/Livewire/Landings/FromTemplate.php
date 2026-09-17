<?php

namespace App\Livewire\Landings;

use App\Livewire\Landings\Concerns\InteractsWithAiGeneration;
use App\Livewire\Landings\Concerns\InteractsWithLandingTags;
use App\Livewire\Landings\Concerns\InteractsWithTemplateForm;
use App\Models\LandingTemplate;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use TrafficOps\TemplateDsl\TemplateEngine;

class FromTemplate extends Component
{
    use InteractsWithAiGeneration;
    use InteractsWithLandingTags;
    use InteractsWithTemplateForm;
    use WithFileUploads;

    #[Locked]
    public LandingTemplate $template;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    public function mount(LandingTemplate $template, TemplateEngine $engine): void
    {
        $this->activeUser();
        $this->template = $template;
        $this->values = $engine->defaults($template->definition);
        $this->initializeAiGeneration();
    }

    public function updatedName(): void
    {
        if ($this->slug === '') {
            $this->slug = Str::slug($this->name);
        }
    }

    public function create(TemplateLandingService $landings): void
    {
        $user = $this->activeUser();
        $this->name = trim($this->name);
        $this->slug = Str::lower(trim($this->slug));

        $attributes = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', Rule::unique('landings', 'slug')],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $tags = $this->validateTags();

        $landing = $landings->create($this->template, $attributes, $this->values, $this->uploads, $user);

        $landing->syncTags($tags);

        session()->flash('saved', 'Landing created from template. Add a system or custom domain to publish it.');
        $this->redirectRoute('landings.show', $landing, navigate: true);
    }

    public function render()
    {
        $this->activeUser();

        return view('livewire.landings.from-template', array_merge($this->generationViewData(), [
            'tagSuggestions' => $this->tagSuggestions(),
            'sections' => $this->template->definition['sections'],
        ]))->layout('components.layouts.app', ['title' => 'Create from template · Fast Landings']);
    }
}
