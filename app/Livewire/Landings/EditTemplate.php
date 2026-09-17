<?php

namespace App\Livewire\Landings;

use App\Livewire\Landings\Concerns\InteractsWithTemplateForm;
use App\Models\Landing;
use App\Models\LandingTemplate;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use TrafficOps\TemplateDsl\TemplateEngine;

class EditTemplate extends Component
{
    use InteractsWithTemplateForm;
    use WithFileUploads;

    #[Locked]
    public string $landingId;

    #[Locked]
    public ?string $baseReleaseId = null;

    #[Locked]
    public ?LandingTemplate $template = null;

    public string $templateId = '';

    public function mount(Landing $landing): void
    {
        $this->activeUser();
        $this->landingId = $landing->id;
        $this->baseReleaseId = $landing->activeRelease?->id;
        $this->templateId = request()->boolean('change') ? '' : ($landing->landing_template_id ?? '');
        $this->updatedTemplateId();
    }

    public function updatedTemplateId(): void
    {
        $this->activeUser();
        $this->resetValidation();
        $this->template = null;
        $this->values = [];
        $this->uploads = [];
        $this->formVersion++;
        if ($this->templateId === '') {
            return;
        }

        $this->validate(['templateId' => ['required', 'string', Rule::exists('landing_templates', 'id')]]);
        $this->template = LandingTemplate::query()->findOrFail($this->templateId);
        $release = $this->landing()->releases()->find($this->baseReleaseId);
        $this->values = $release?->landing_template_id === $this->templateId && $release->template_values !== null
            ? $release->template_values
            : app(TemplateEngine::class)->defaults($this->template->definition);
    }

    public function save(TemplateLandingService $landings): void
    {
        $user = $this->activeUser();
        $this->validate(['templateId' => ['required', 'string', Rule::exists('landing_templates', 'id')]]);
        abort_unless($this->template?->id === $this->templateId, 422);

        $landing = $landings->update($this->landing(), $this->template, $this->values, $this->uploads, $user, $this->baseReleaseId);

        session()->flash('saved', 'Landing updated from template. New release activated.');
        $this->redirectRoute('landings.show', $landing, navigate: true);
    }

    public function render()
    {
        $this->activeUser();
        $landing = $this->landing();

        return view('livewire.landings.edit-template', [
            'landing' => $landing,
            'templates' => LandingTemplate::query()->orderBy('name')->get(),
            'sections' => $this->template?->definition['sections'] ?? [],
        ])->layout('components.layouts.app', ['title' => 'Edit content · '.$landing->name.' · Fast Landings']);
    }

    private function landing(): Landing
    {
        return Landing::query()->findOrFail($this->landingId);
    }
}
