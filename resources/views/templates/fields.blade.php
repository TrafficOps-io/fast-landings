<div class="grid gap-5 sm:grid-cols-2">
    @foreach ($fields as $field)
        @php
            $fieldPath = $prefix === '' ? $field['name'] : $prefix.'.'.$field['name'];
            $valuePath = 'values.'.$fieldPath;
            $uploadPath = 'uploads.'.$fieldPath;
            $fieldId = 'template-field-'.md5($fieldPath).'-'.$formVersion;
            $fieldValue = data_get($values, $fieldPath);
            $fieldLabel = $field['label'] ?? $field['name'];
            $fieldType = $field['type'];
        @endphp
        <div wire:key="{{ $fieldId }}" @class(['min-w-0', 'sm:col-span-2' => in_array($fieldType, ['textarea', 'wysiwyg', 'markdown', 'group', 'repeater', 'image'], true)])>
            @if ($fieldType === 'group')
                @if (filled($field['label'] ?? null))
                    <fieldset class="min-w-0 rounded-lg border border-base-300 p-4">
                        <legend class="px-2 text-sm font-semibold">{{ $fieldLabel }}</legend>
                @endif
                    @if (!empty($field['help']))<p class="mb-4 text-xs leading-5 text-base-content/55">{{ $field['help'] }}</p>@endif
                    @include('templates.fields', ['fields' => $field['fields'], 'prefix' => $fieldPath])
                @if (filled($field['label'] ?? null))
                    </fieldset>
                @endif
            @elseif ($fieldType === 'repeater')
                @php
                    $items = is_array($fieldValue) ? $fieldValue : [];
                @endphp
                <fieldset class="min-w-0">
                    <legend class="text-sm font-semibold">{{ $fieldLabel }} <span class="ml-1 font-normal text-base-content/50">{{ count($items) }} / {{ $field['max_items'] ?? 50 }}</span></legend>
                    @if (!empty($field['help']))<p class="mt-1 text-xs leading-5 text-base-content/55">{{ $field['help'] }}</p>@endif
                    <div class="mt-3 grid gap-3">
                        @foreach ($items as $itemIndex => $item)
                            <div class="min-w-0 rounded-lg border border-base-300 bg-base-200/30 p-4" wire:key="{{ $fieldId }}-row-{{ $itemIndex }}">
                                <div class="mb-4 flex items-center justify-between gap-3">
                                    <span class="text-xs font-semibold text-base-content/60">{{ $fieldLabel }} · {{ $loop->iteration }}</span>
                                    <button type="button" class="d-btn d-btn-ghost d-btn-xs text-error" wire:click="removeItem(@js($fieldPath), {{ $itemIndex }})" wire:loading.attr="disabled" :disabled="uploadsInProgress > 0 || {{ count($items) <= ($field['min_items'] ?? 0) ? 'true' : 'false' }}" aria-label="Remove {{ $fieldLabel }} item {{ $loop->iteration }}">Remove</button>
                                </div>
                                @include('templates.fields', ['fields' => $field['fields'], 'prefix' => $fieldPath.'.'.$itemIndex])
                            </div>
                        @endforeach
                        @if ($items === [])<p class="rounded-lg border border-dashed border-base-300 p-4 text-sm text-base-content/50">No items yet. Add an item to include this block.</p>@endif
                    </div>
                    <button type="button" class="d-btn d-btn-outline d-btn-sm mt-3" wire:click="addItem(@js($fieldPath))" wire:loading.attr="disabled" :disabled="uploadsInProgress > 0 || {{ count($items) >= ($field['max_items'] ?? 50) ? 'true' : 'false' }}">+ Add item</button>
                </fieldset>
            @elseif ($fieldType === 'checkbox')
                <label class="flex items-start gap-3" for="{{ $fieldId }}">
                    <input id="{{ $fieldId }}" type="checkbox" wire:model.live="{{ $valuePath }}" class="d-checkbox d-checkbox-primary mt-0.5" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                    <span class="grid gap-1"><span class="text-sm font-medium">{{ $fieldLabel }}</span>@if (!empty($field['help']))<span id="{{ $fieldId }}-help" class="text-xs leading-5 text-base-content/55">{{ $field['help'] }}</span>@endif</span>
                </label>
            @else
                <label id="{{ $fieldId }}-label" for="{{ $fieldId }}" class="mb-1.5 block text-sm font-medium">{{ $fieldLabel }} @if ($field['required'] ?? false)<span class="text-error" aria-hidden="true">*</span>@endif</label>
                @if (in_array($fieldType, ['wysiwyg', 'markdown'], true))
                    @include('templates.editor')
                @elseif (in_array($fieldType, ['text', 'textarea', 'url', 'email'], true))
                    @include('templates.macro-input')
                @elseif ($fieldType === 'select')
                    <select id="{{ $fieldId }}" wire:model.live="{{ $valuePath }}" class="d-select d-select-bordered w-full" @required($field['required'] ?? false) aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                        @unless (array_key_exists('', $field['options']))<option value="" @disabled($field['required'] ?? false) @selected($fieldValue === '' || $fieldValue === null)>Select an option</option>@endunless
                        @foreach ($field['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $fieldValue === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
                    </select>
                @elseif ($fieldType === 'color')
                    @php
                        $pickerColor = is_string($fieldValue) ? $fieldValue : '';
                        if (preg_match('/^#[a-fA-F0-9]{3}$/', $pickerColor)) {
                            $pickerColor = '#'.$pickerColor[1].$pickerColor[1].$pickerColor[2].$pickerColor[2].$pickerColor[3].$pickerColor[3];
                        }
                        $pickerColor = preg_match('/^#[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?$/', $pickerColor) ? substr($pickerColor, 0, 7) : '#000000';
                    @endphp
                    <div class="flex items-center gap-3">
                        <input id="{{ $fieldId }}" type="color" value="{{ $pickerColor }}" wire:change="$set(@js($valuePath), $event.target.value)" class="h-11 w-16 cursor-pointer rounded-lg border border-base-300 bg-base-100 p-1" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                        <input type="text" wire:model.live.debounce.300ms="{{ $valuePath }}" class="d-input d-input-bordered min-w-0 flex-1 font-mono" aria-label="{{ $fieldLabel }} color value" placeholder="#2563eb" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                    </div>
                @elseif ($fieldType === 'range')
                    @php
                        $rangeValue = is_numeric($fieldValue) ? (float) $fieldValue : 0;
                        $rangeMin = $field['min'] ?? min(0, $field['max'] ?? 0, $rangeValue);
                        $rangeMax = $field['max'] ?? max(100, $field['min'] ?? 100, $rangeValue);
                    @endphp
                    <div class="flex min-h-11 items-center gap-4">
                        <input id="{{ $fieldId }}" type="range" wire:model.live.debounce.150ms="{{ $valuePath }}" min="{{ $rangeMin }}" max="{{ $rangeMax }}" step="{{ $field['step'] ?? 'any' }}" class="d-range d-range-primary d-range-sm min-w-0 flex-1" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                        <input type="number" wire:model.live.debounce.300ms="{{ $valuePath }}" @if (isset($field['min'])) min="{{ $field['min'] }}" @endif @if (isset($field['max'])) max="{{ $field['max'] }}" @endif step="{{ $field['step'] ?? 'any' }}" class="d-input d-input-bordered w-24 font-mono text-sm" aria-label="{{ $fieldLabel }} value" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                    </div>
                @elseif ($fieldType === 'image')
                    @include('templates.image-field')
                @else
                    <input id="{{ $fieldId }}" type="{{ in_array($fieldType, ['number', 'url', 'email'], true) ? $fieldType : 'text' }}" wire:model.live.debounce.350ms="{{ $valuePath }}" class="d-input d-input-bordered w-full" @required($field['required'] ?? false) @if ($fieldType === 'number') @if (isset($field['min'])) min="{{ $field['min'] }}" @endif @if (isset($field['max'])) max="{{ $field['max'] }}" @endif step="{{ $field['step'] ?? 'any' }}" @elseif (isset($field['max'])) maxlength="{{ $field['max'] }}" @endif aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}">
                @endif
                @if (!empty($field['help']))<p id="{{ $fieldId }}-help" class="mt-1.5 text-xs leading-5 text-base-content/55">{{ $field['help'] }}</p>@endif
            @endif
            @error($valuePath)<p id="{{ $fieldId }}-error" class="mt-1.5 text-sm text-error" role="alert">{{ $message }}</p>@enderror
        </div>
    @endforeach
</div>
