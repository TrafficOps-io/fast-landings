<section aria-label="AI content generation" class="rounded-box border border-base-300 bg-base-100 p-5 sm:p-6">
    <details wire:ignore.self @if ($generation && !$generation->applied_at) open @endif>
        <summary class="cursor-pointer text-base font-semibold">Generate content with AI <span class="ml-2 text-sm font-normal text-base-content/55">Optional</span></summary>
        <div class="mt-4 grid gap-5">
            <p class="max-w-3xl text-sm text-base-content/65">Describe your landing. The AI uses this template’s field instructions and structure. Apply the result to the editor, review it, then create your landing.</p>
            @if ($aiConnections->isEmpty())
                <div class="rounded-lg bg-base-200 p-4 text-sm">No AI connections are available. Ask an administrator to add one in AI integrations.</div>
            @else
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(12rem,20rem)]">
                    <label class="grid gap-1.5">
                        <span class="text-sm font-medium">What should the landing contain?</span>
                        <textarea wire:model="aiPrompt" class="d-textarea d-textarea-bordered min-h-32 w-full" maxlength="10000" placeholder="Describe the product, audience, language, tone, and any content or number of items you want." @error('aiPrompt') aria-invalid="true" @enderror></textarea>
                        @error('aiPrompt')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <label class="grid content-start gap-1.5">
                        <span class="text-sm font-medium">AI connection</span>
                        <select wire:model.live="aiIntegrationId" class="d-select d-select-bordered w-full" @error('aiIntegrationId') aria-invalid="true" @enderror>
                            <option value="">Choose a connection</option>
                            @foreach ($aiConnections as $connection)
                                <option value="{{ $connection->id }}">{{ $connection->name }} · {{ $connection->provider->label() }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs leading-5 text-base-content/55">Your prompt and uploaded images are sent to this connection. Generation uses its API credits.</span>
                        @if ($aiSupportsImages !== null)
                            <span class="text-xs leading-5 text-base-content/55">{{ $aiSupportsVision ? 'Accepts uploaded images.' : 'Text input only.' }} {{ $aiSupportsImages ? 'Can generate new images.' : 'Does not generate new images.' }}</span>
                        @endif
                        @error('aiIntegrationId')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    </label>
                </div>

                @if ($generationFields['arrays'])
                    <fieldset class="grid gap-3">
                        <legend class="mb-2 text-sm font-medium">Number of items <span class="font-normal text-base-content/55">optional</span></legend>
                        <p class="text-xs text-base-content/55">Leave blank to use your prompt. For nested arrays, the count applies to each parent item.</p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($generationFields['arrays'] as $index => $field)
                                <label class="grid gap-1.5" wire:key="ai-array-{{ $index }}">
                                    <span class="text-sm">{{ $field['label'] }}</span>
                                    <input type="number" min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="1" wire:model="aiArrayCounts.{{ $index }}" class="d-input d-input-bordered w-full" placeholder="{{ $field['min'] }}–{{ $field['max'] }}">
                                    @error('aiArrayCounts.'.$index)<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                                </label>
                            @endforeach
                        </div>
                        @error('aiArrayCounts')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    </fieldset>
                @endif

                <div class="grid gap-5 md:grid-cols-2">
                    @foreach (['aiSiteImages' => ['Images for the site', 'The AI may place these images into matching image fields on the landing.'], 'aiReferenceImages' => ['Reference images', 'Used only to guide the content and image generation. These files will not be placed on the site.']] as $property => [$label, $help])
                        <div class="grid content-start gap-2 rounded-lg border border-base-300 p-4">
                            <label class="grid gap-2">
                                <span class="text-sm font-medium">{{ $label }} <span class="font-normal text-base-content/55">optional</span></span>
                                <span class="text-xs leading-5 text-base-content/60">{{ $help }}</span>
                                <input type="file" wire:key="ai-upload-{{ $property }}-{{ $aiUploadVersion }}" wire:model="{{ $property }}" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/avif" class="d-file-input d-file-input-bordered d-file-input-sm w-full">
                            </label>
                            <span class="text-xs text-base-content/50">Up to 8 images; 10 MB each. Combined upload limit: 20 MB.</span>
                            @foreach ($this->{$property} as $index => $file)
                                @if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                <div class="flex items-center justify-between gap-2 text-xs" wire:key="{{ $property }}-{{ $index }}-{{ $file->getFilename() }}">
                                    <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                                    <button type="button" wire:click="removeAiImage('{{ $property }}', {{ $index }})" class="d-btn d-btn-ghost d-btn-xs" aria-label="Remove {{ $file->getClientOriginalName() }}">Remove</button>
                                </div>
                                @endif
                            @endforeach
                            @error($property)<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                            @error($property.'.*')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                </div>

                @if ($generationFields['images'])
                    <fieldset class="grid gap-3">
                        <legend class="mb-2 text-sm font-medium">Generate new images <span class="font-normal text-base-content/55">optional</span></legend>
                        <p class="text-xs text-base-content/55">Only checked fields receive AI-generated images. An array field generates an image for each item, up to 8 images total. Requires a connection that supports image generation.</p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($generationFields['images'] as $field)
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" wire:model="aiImageFields" value="{{ $field['path'] }}" class="d-checkbox d-checkbox-sm">
                                    <span>{{ $field['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('aiImageFields')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                        @error('aiImageFields.*')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    </fieldset>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="generateContent" wire:loading.attr="disabled" wire:target="generateContent,applyGeneration,aiSiteImages,aiReferenceImages" :disabled="uploadsInProgress > 0 || {{ $generation?->isRunning() ? 'true' : 'false' }}" class="d-btn d-btn-outline d-btn-sm">
                        <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="generateContent"></span>
                        {{ $generation ? 'Generate again' : 'Generate content' }}
                    </button>
                    <span class="text-xs text-base-content/55">Your editor values stay unchanged until you apply a result.</span>
                </div>
            @endif

            @error('generation')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror

            @if ($generation)
                <div @if ($generation->isRunning()) wire:poll.3s="refreshGeneration" @endif class="rounded-lg border border-base-300 p-4" role="status">
                    @if ($generation->isRunning())
                        <p class="flex items-center gap-2 text-sm"><span class="d-loading d-loading-spinner d-loading-sm"></span>{{ $generation->status === 'pending' ? 'Waiting for the generation worker…' : 'Generating content… This may take a few minutes, especially with images.' }}</p>
                        <p class="mt-2 text-xs text-base-content/55">You can keep editing. The result will be available here when it is ready.</p>
                    @elseif ($generation->status === 'failed')
                        <p class="text-sm text-error">{{ $generation->error }}</p>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div><p class="text-sm font-medium">{{ $generation->applied_at ? 'Generated result was applied previously' : 'Generated content is ready' }}</p><p class="mt-1 text-xs text-base-content/60">Apply to review and edit the result in the sections below. This replaces the current template field values.</p></div>
                            <button type="button" wire:click="applyGeneration" wire:confirm="Replace the current template field values with the generated result?" wire:loading.attr="disabled" wire:target="applyGeneration,generateContent" :disabled="uploadsInProgress > 0" class="d-btn d-btn-primary d-btn-sm">{{ $generation->applied_at ? 'Apply again' : 'Apply to editor' }}</button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </details>
</section>
