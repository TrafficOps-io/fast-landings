<?php

namespace App\Livewire\Landings\Concerns;

use Spatie\Tags\Tag;

trait InteractsWithLandingTags
{
    public array $tags = [];

    protected function validateTags(): array
    {
        $this->validate([
            'tags' => ['array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:60'],
        ]);

        return $this->tags = collect($this->tags)
            ->map(fn (string $tag) => trim($tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->unique()->values()->all();
    }

    protected function tagSuggestions(): array
    {
        return Tag::query()->whereNull('type')->orderBy('order_column')->get()
            ->pluck('name')->sort()->values()->all();
    }
}
