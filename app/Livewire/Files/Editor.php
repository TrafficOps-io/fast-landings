<?php

namespace App\Livewire\Files;

use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\FileWorkspaceService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Editor extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $selectedPath = '';

    #[Locked]
    public string $content = '';

    #[Locked]
    public bool $editable = false;

    #[Locked]
    public int $selectionVersion = 0;

    #[Locked]
    public bool $pendingChanges = false;

    public string $newPath = '';

    public string $newContent = '';

    public string $renamePath = '';

    public string $uploadPath = '';

    public $fileUpload;

    public int $uploadVersion = 0;

    public function mount(?LandingTemplate $template = null, ?LandingRelease $release = null): void
    {
        abort_unless(($template?->exists ?? false) xor ($release?->exists ?? false), 404);
        $target = $template?->exists ? $template : $release;
        try {
            $this->workspaceId = $this->workspaces()->open($target, $this->activeUser());
        } catch (ValidationException $exception) {
            // Only the active release can be drafted: send the operator back to the landing
            // with the reason. Livewire would otherwise swallow the exception and render.
            abort(redirect()->route('landings.show', $release->landing_id)->withErrors($exception->errors()));
        }
        $info = $this->info();
        $this->selectFile($info['entrypoint']);
    }

    public function selectFile(string $path): void
    {
        $file = collect($this->files())->firstWhere('path', $path);
        abort_unless($file, 404);
        $this->resetValidation();
        $this->selectedPath = $path;
        $this->renamePath = $path;
        $this->editable = $file['editable'];
        $this->content = $file['editable'] ? $this->workspaces()->read($this->workspaceId, $path, $this->activeUser()) : '';
        $this->selectionVersion++;
    }

    public function saveFile(string $content): bool
    {
        $this->resetValidation();
        try {
            $this->workspaces()->write($this->workspaceId, $this->selectedPath, $content, $this->activeUser());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return false;
        }
        $this->content = $content;
        $this->pendingChanges = true;
        // Keep the active Monaco instance alive until a following file action finishes.
        // Morphing its changed x-data config here would unmount it before save() resolves.
        $this->skipRender();

        return true;
    }

    public function createFile(): void
    {
        $this->resetValidation();
        $this->validate(['newPath' => ['required', 'string'], 'newContent' => ['string']]);
        $this->workspaces()->write($this->workspaceId, $this->newPath, $this->newContent, $this->activeUser(), true);
        $this->pendingChanges = true;
        $this->selectFile($this->newPath);
        $this->reset('newPath', 'newContent');
    }

    public function uploadFile(): void
    {
        $this->resetValidation();
        $this->validate([
            'fileUpload' => ['required', 'file', 'max:'.config('fast-landings.max_upload_kb')],
            'uploadPath' => ['nullable', 'string'],
        ]);
        $path = $this->uploadPath !== '' ? $this->uploadPath : $this->fileUpload->getClientOriginalName();
        $this->workspaces()->upload($this->workspaceId, $path, $this->fileUpload, $this->activeUser());
        $this->pendingChanges = true;
        $this->selectFile($path);
        $this->reset('fileUpload', 'uploadPath');
        $this->uploadVersion++;
    }

    public function renameFile(): void
    {
        $this->resetValidation();
        $this->validate(['renamePath' => ['required', 'string']]);
        $this->workspaces()->rename($this->workspaceId, $this->selectedPath, $this->renamePath, $this->activeUser());
        $this->pendingChanges = true;
        $this->selectFile($this->renamePath);
    }

    public function deleteFile(): void
    {
        $this->resetValidation();
        $this->workspaces()->delete($this->workspaceId, $this->selectedPath, $this->activeUser());
        $this->pendingChanges = true;
        $this->reset('selectedPath', 'content', 'editable', 'renamePath');
        $this->selectionVersion++;
        if ($file = $this->files()[0] ?? null) {
            $this->selectFile($file['path']);
        }
    }

    /** @param bool $detachFromTemplate Explicit confirmation that a template landing becomes a file landing. */
    public function publish(bool $detachFromTemplate = false): bool
    {
        $this->resetValidation();
        try {
            $result = $this->workspaces()->publish($this->workspaceId, $this->activeUser(), $detachFromTemplate);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return false;
        }
        $this->pendingChanges = false;
        session()->flash('saved', match (true) {
            $result instanceof LandingTemplate => 'Template files saved. Existing landing releases are unchanged.',
            $detachFromTemplate => 'Detached from template: a new release was activated and this is now a file landing.',
            default => 'Files saved and a new landing release activated.',
        });
        $this->redirect($result instanceof LandingTemplate ? route('templates.index') : route('landings.show', $result->landing_id), navigate: true);
        $this->skipRender();

        return true;
    }

    public function discard(): bool
    {
        $info = $this->info();
        $this->workspaces()->discard($this->workspaceId, $this->activeUser());
        $this->pendingChanges = false;
        $this->redirect($this->backUrl($info), navigate: true);
        $this->skipRender();

        return true;
    }

    public function render()
    {
        ['info' => $info, 'files' => $files, 'sources' => $sources] = $this->workspaces()->snapshot($this->workspaceId, $this->activeUser());

        return view('livewire.files.editor', [
            'info' => $info,
            'files' => $files,
            'sources' => $sources,
            'backUrl' => $this->backUrl($info),
        ])->layout('components.layouts.app', ['title' => 'Files · '.$info['name'].' · Fast Landings']);
    }

    private function files(): array
    {
        return $this->workspaces()->files($this->workspaceId, $this->activeUser());
    }

    private function info(): array
    {
        return $this->workspaces()->info($this->workspaceId, $this->activeUser());
    }

    private function backUrl(array $info): string
    {
        return $info['kind'] === 'template' ? route('templates.index') : route('landings.show', $info['landing_id']);
    }

    private function activeUser(): User
    {
        $user = User::query()->find(auth()->id());
        abort_unless($user?->is_active, 403);

        return $user;
    }

    private function workspaces(): FileWorkspaceService
    {
        return app(FileWorkspaceService::class);
    }
}
