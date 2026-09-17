<?php

namespace App\Livewire;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\User;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.dashboard', [
            'landingCount' => Landing::query()->count(),
            'releaseCount' => LandingRelease::query()->count(),
            'activeDomainCount' => Domain::query()->where('status', DomainStatus::Active)->count(),
            'userCount' => User::query()->where('is_active', true)->count(),
            'recentLandings' => Landing::query()->withCount('domains')->with('activeRelease')->latest()->limit(5)->get(),
        ])->layout('components.layouts.app', ['title' => 'Dashboard · Fast Landings']);
    }
}
