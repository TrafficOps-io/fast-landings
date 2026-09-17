<?php

namespace App\Livewire\Templates;

use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplatePreviewService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    public $templateUpload;

    public int $uploadVersion = 0;

    #[Locked]
    public ?string $editingTemplateId = null;

    public string $name = '';

    public string $description = '';

    public $replacementUpload;

    public int $replacementUploadVersion = 0;

    public function mount(TemplatePreviewService $previews): void
    {
        $this->activeUser();
        $previews->requestMissing();
    }

    public function refreshPreview(string $templateId, TemplatePreviewService $previews): void
    {
        abort_unless($this->activeUser()->isAdministrator(), 403);
        $previews->request(LandingTemplate::query()->findOrFail($templateId), force: true);
    }

    public function editTemplate(string $templateId): void
    {
        abort_unless($this->activeUser()->isAdministrator(), 403);
        $template = LandingTemplate::query()->findOrFail($templateId);
        $this->cancelEdit();
        $this->editingTemplateId = $template->id;
        $this->name = $template->name;
        $this->description = $template->description ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingTemplateId', 'name', 'description', 'replacementUpload');
        $this->resetValidation(['name', 'description', 'replacementUpload']);
        $this->replacementUploadVersion++;
    }

    public function saveTemplate(TemplateArchiveService $archives): void
    {
        $user = $this->activeUser();
        abort_unless($user->isAdministrator(), 403);
        $template = LandingTemplate::query()->findOrFail($this->editingTemplateId);
        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'replacementUpload' => ['nullable', 'file', 'extensions:html,php,txt,tpl,zip', 'max:'.config('fast-landings.max_upload_kb')],
        ]);

        try {
            $archives->update($template, ['name' => $this->name, 'description' => $this->description], $this->replacementUpload, $user);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['templateUpload'])) {
                $errors['replacementUpload'] = $errors['templateUpload'];
                unset($errors['templateUpload']);
            }
            throw ValidationException::withMessages($errors);
        }

        $this->cancelEdit();
        session()->flash('saved', 'Template updated. Existing landing releases are unchanged.');
    }

    public function importTemplate(TemplateArchiveService $archives): void
    {
        $user = $this->activeUser();
        abort_unless($user->isAdministrator(), 403);

        $this->validate([
            'templateUpload' => ['required', 'file', 'extensions:html,php,txt,tpl,zip', 'max:'.config('fast-landings.max_upload_kb')],
        ]);

        $template = $archives->import($this->templateUpload, $user);

        $this->reset('templateUpload');
        $this->uploadVersion++;
        session()->flash('saved', 'Template “'.$template->name.'” imported. It is ready to create landings.');
    }

    public function deleteTemplate(string $templateId, TemplateArchiveService $archives): void
    {
        abort_unless($this->activeUser()->isAdministrator(), 403);

        $archives->delete(LandingTemplate::query()->findOrFail($templateId));

        if ($this->editingTemplateId === $templateId) {
            $this->cancelEdit();
        }

        session()->flash('saved', 'Template deleted. Landings already created from it are unchanged.');
    }

    public function render()
    {
        $user = $this->activeUser();

        $templates = LandingTemplate::query()->latest()->get();

        return view('livewire.templates.index', [
            'templates' => $templates,
            'hasPendingPreviews' => $templates->contains(fn ($template) => in_array($template->preview_status, ['queued', 'processing'], true)),
            'canManageTemplates' => $user->isAdministrator(),
        ])->layout('components.layouts.app', ['title' => 'Templates · Fast Landings']);
    }

    private function activeUser(): User
    {
        $user = User::query()->whereKey(auth()->id())->where('is_active', true)->first();
        abort_unless($user, 403);

        return $user;
    }
}
