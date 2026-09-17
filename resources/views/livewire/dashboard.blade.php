<x-ui::page>
    <x-ui::page-header title="Dashboard" eyebrow="Fast Landings">
        <x-slot:actions>
            <a href="{{ route('landings.index', ['create' => 1]) }}" class="d-btn d-btn-primary d-btn-sm" wire:navigate>Deploy landing</a>
        </x-slot:actions>
    </x-ui::page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['Landings', $landingCount], ['Releases', $releaseCount], ['Active domains', $activeDomainCount], ['Active users', $userCount]] as [$label, $value])
            <div class="ui-panel">
                <p class="text-sm text-base-content/60">{{ $label }}</p>
                <p class="mt-2 text-3xl font-semibold tracking-tight">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <x-ui::panel title="Recent landings" description="The latest projects and their currently active release.">
        @if ($recentLandings->isEmpty())
            <x-ui::empty-state title="No landings yet" description="Upload a ZIP archive to publish your first landing.">
                <x-slot:action><a href="{{ route('landings.index', ['create' => 1]) }}" class="d-btn d-btn-primary d-btn-sm" wire:navigate>Deploy landing</a></x-slot:action>
            </x-ui::empty-state>
        @else
            <x-ui::list>
                @foreach ($recentLandings as $landing)
                    <x-ui::list-row :href="route('landings.show', $landing)" :title="$landing->name">
                        <x-slot:meta>{{ $landing->domains_count }} domain(s) · {{ $landing->activeRelease ? 'release active' : 'no active release' }}</x-slot:meta>
                        <x-slot:actions><x-ui::status-badge :label="$landing->is_active ? 'Active' : 'Paused'" :tone="$landing->is_active ? 'success' : 'warning'" /></x-slot:actions>
                    </x-ui::list-row>
                @endforeach
            </x-ui::list>
        @endif
    </x-ui::panel>
</x-ui::page>
