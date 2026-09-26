<x-ui::page :x-data="'fileManager('.\Illuminate\Support\Js::from($workspaceId).')'" x-on:file-editor-dirty="dirty = $event.detail.dirty">
    <x-ui::page-header :title="$info['name']" :eyebrow="$info['kind'] === 'template' ? 'Template files' : 'Landing files'" :back="$backUrl" back-label="Back">
        <x-slot:actions>
            <button type="button" class="d-btn d-btn-outline d-btn-sm" x-on:click="download(@js(route('files.download', $workspaceId)))" :disabled="busy">Download ZIP</button>
            <button type="button" class="d-btn d-btn-ghost d-btn-sm" x-on:click="if ((!dirty && !$wire.pendingChanges) || confirm('Discard all file changes?')) run('discard')" :disabled="busy">Cancel</button>
            @if ($info['kind'] === 'landing' && $info['template_linked'])
                <button type="button" class="d-btn d-btn-warning d-btn-sm" x-on:click="if (confirm('Detach from template? The new release will be a file landing: its content is edited as files and no longer through template values. The previous release keeps its template snapshot and can be activated to restore it.')) run('publish', true)" :disabled="busy || (!dirty && !$wire.pendingChanges)">
                    Detach from template and activate
                </button>
            @else
                <button type="button" class="d-btn d-btn-primary d-btn-sm" x-on:click="run('publish')" :disabled="busy || (!dirty && !$wire.pendingChanges)">
                    {{ $info['kind'] === 'template' ? 'Save template' : 'Save and activate' }}
                </button>
            @endif
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::feedback />
    <p x-show="error" x-text="error" class="d-alert d-alert-error" role="alert" x-cloak></p>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-box border border-base-300 bg-base-200/50 px-4 py-3 text-sm">
        <p class="text-base-content/65">
            {{ $info['kind'] === 'template' ? 'Edit source files and assets, then save the template to validate all changes together.' : 'Changes are saved as a new active release. You can restore the previous release from the landing page.' }}
        </p>
        <span class="d-badge d-badge-outline shrink-0" x-text="dirty ? 'Unsaved file' : ($wire.pendingChanges ? 'Unpublished changes' : 'No changes')"></span>
    </div>
    @if ($info['kind'] === 'landing' && $info['template_linked'])
        <p class="d-alert d-alert-warning text-sm" role="alert"><strong>This is a template landing.</strong> Publishing these files is a <strong>Detach from template</strong>: the new release becomes a file landing, and its content is edited here as files instead of through template values. The previous release keeps its template snapshot; activate it to return to the template.</p>
    @endif

    <div class="grid min-w-0 gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <aside class="min-w-0 space-y-4">
            <div class="rounded-box border border-base-300 bg-base-100">
                <div class="border-b border-base-300 p-3">
                    <label class="grid gap-2"><span class="text-sm font-semibold">Files <span class="font-normal text-base-content/50">({{ count($files) }})</span></span><input x-model="search" type="search" placeholder="Filter files…" aria-label="Filter files" class="d-input d-input-sm d-input-bordered w-full"></label>
                </div>
                <nav aria-label="Package files" class="max-h-96 overflow-auto p-2 lg:max-h-[34rem]">
                    @forelse ($files as $file)
                        <button type="button" wire:key="file-{{ $file['path'] }}" x-show="@js(mb_strtolower($file['path'])).includes(search.toLowerCase())" x-on:click="run('selectFile', @js($file['path']))" :disabled="busy" @if ($selectedPath === $file['path']) aria-current="true" @endif title="{{ $file['path'] }}" class="flex w-full min-w-0 items-center gap-2 rounded-lg px-2 py-2 text-left text-xs {{ $selectedPath === $file['path'] ? 'bg-primary/10 text-primary' : 'hover:bg-base-200' }}">
                            <span aria-hidden="true" class="shrink-0 text-base-content/45">{{ $file['editable'] ? '‹›' : '◈' }}</span><span class="min-w-0 flex-1 break-all font-mono">{{ $file['path'] }}</span><span class="shrink-0 text-[10px] text-base-content/45">{{ \Illuminate\Support\Number::fileSize($file['size']) }}</span>
                        </button>
                    @empty
                        <p class="p-3 text-sm text-base-content/55">Add a file to get started.</p>
                    @endforelse
                </nav>
            </div>

            <details wire:ignore.self class="rounded-box border border-base-300 bg-base-100 p-4">
                <summary class="cursor-pointer text-sm font-semibold">New file</summary>
                <form x-on:submit.prevent="run('createFile')" class="mt-3 grid gap-3">
                    <label class="grid gap-1.5"><span class="text-xs">File path</span><input wire:model="newPath" required placeholder="assets/style.css" class="d-input d-input-sm d-input-bordered w-full font-mono"></label>
                    <p class="text-xs text-base-content/55">Use / to create nested folders.</p>
                    <button type="submit" class="d-btn d-btn-outline d-btn-sm" :disabled="busy">Create file</button>
                </form>
            </details>
            <details wire:ignore.self class="rounded-box border border-base-300 bg-base-100 p-4">
                <summary class="cursor-pointer text-sm font-semibold">Upload file</summary>
                <form x-on:submit.prevent="run('uploadFile')" class="mt-3 grid gap-3">
                    <label class="grid gap-1.5"><span class="text-xs">File</span><input wire:model="fileUpload" wire:key="file-upload-{{ $uploadVersion }}" type="file" required class="d-file-input d-file-input-sm d-file-input-bordered w-full"></label>
                    <label class="grid gap-1.5"><span class="text-xs">Destination path (optional)</span><input wire:model="uploadPath" placeholder="assets/image.png" class="d-input d-input-sm d-input-bordered w-full font-mono"></label>
                    <p class="text-xs text-base-content/55">Up to {{ (int) (config('fast-landings.max_upload_kb') / 1024) }} MB. Existing files are never overwritten.</p>
                    <button type="submit" class="d-btn d-btn-outline d-btn-sm" :disabled="busy" wire:loading.attr="disabled" wire:target="fileUpload">Upload</button>
                    <span wire:loading wire:target="fileUpload" class="text-xs text-base-content/55" role="status">Uploading…</span>
                </form>
            </details>
        </aside>

        <section class="min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100" aria-label="File editor">
            @if ($selectedPath !== '')
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-300 px-4 py-3">
                    <h2 class="min-w-0 break-all font-mono text-sm font-semibold">{{ $selectedPath }}</h2>
                    <div class="flex gap-2">
                        <a href="{{ route('files.show', ['workspace' => $workspaceId, 'path' => $selectedPath]) }}" target="_blank" rel="noopener" class="d-btn d-btn-ghost d-btn-xs">View ↗</a>
                        <button type="button" x-on:click="download(@js(route('files.show', ['workspace' => $workspaceId, 'path' => $selectedPath, 'download' => 1])))" :disabled="busy" class="d-btn d-btn-ghost d-btn-xs">Download</button>
                    </div>
                </div>
                @if ($editable)
                    <div wire:key="source-{{ $workspaceId }}-{{ $selectionVersion }}" x-data="fileEditor(@js(['path' => $selectedPath, 'content' => $content, 'files' => $files, 'sources' => $sources, 'workspaceId' => $workspaceId, 'readOnly' => false, 'guardNavigation' => false]))">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-4 py-2 text-xs">
                            <span class="text-base-content/55">Monaco · Ctrl/⌘ S to save this file to the draft · Ctrl Space for suggestions</span>
                            <button type="button" class="d-btn d-btn-ghost d-btn-xs" x-on:click="save()" :disabled="saving || !dirty || busy">Save file to draft</button>
                        </div>
                        <p x-show="loading" class="p-4 text-sm text-base-content/55" role="status">Loading editor…</p>
                        <p x-show="error" x-text="error" class="p-4 text-sm text-error" role="alert" x-cloak></p>
                        <div :inert="busy"><div wire:ignore><div x-ref="editor" class="file-editor-canvas h-[34rem] min-h-80 w-full" aria-label="Source code editor"></div></div></div>
                    </div>
                @else
                    <div class="grid min-h-80 place-content-center gap-4 p-8 text-center">
                        @if (preg_match('/\.(png|jpe?g|gif|webp|avif)$/iD', $selectedPath))
                            <img src="{{ route('files.show', ['workspace' => $workspaceId, 'path' => $selectedPath]) }}" alt="Preview of {{ $selectedPath }}" class="mx-auto max-h-96 max-w-full object-contain">
                        @endif
                        <p class="text-sm text-base-content/60">This file is binary or exceeds the 2 MB text editing limit. You can view, download, rename or delete it.</p>
                    </div>
                @endif
                <div class="flex flex-wrap items-end gap-3 border-t border-base-300 p-4">
                    <form x-on:submit.prevent="run('renameFile')" class="flex min-w-0 flex-1 flex-wrap items-end gap-2">
                        <label class="grid min-w-40 flex-1 gap-1.5"><span class="text-xs text-base-content/60">Rename or move file</span><input wire:model="renamePath" required class="d-input d-input-sm d-input-bordered w-full font-mono"></label>
                        <button type="submit" class="d-btn d-btn-outline d-btn-sm" :disabled="busy">Rename</button>
                    </form>
                    <button type="button" class="d-btn d-btn-error d-btn-ghost d-btn-sm" x-on:click="if (confirm('Delete this file from the draft?')) run('deleteFile')" :disabled="busy">Delete file</button>
                </div>
                <p class="px-4 pb-4 text-xs text-base-content/50">Update references after moving or deleting files. Changes take effect when you save {{ $info['kind'] === 'template' ? 'the template' : 'and activate the release' }}.</p>
            @else
                <p class="p-12 text-center text-sm text-base-content/55">Select or create a file.</p>
            @endif
        </section>
    </div>
</x-ui::page>
