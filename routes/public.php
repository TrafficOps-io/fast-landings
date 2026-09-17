<?php

use App\Http\Controllers\CaddyAskController;
use App\Http\Controllers\LandingContentController;
use Illuminate\Support\Facades\Route;

// These routes deliberately do not use Laravel's `web` middleware group. Public
// landing traffic must never start an admin session or emit CSRF/session cookies.
Route::domain(config('fast-landings.panel_domain'))
    ->get('/up', fn () => response('OK', 200, ['Content-Type' => 'text/plain']))
    ->name('health');

Route::get('/internal/caddy/ask', CaddyAskController::class)->name('caddy.ask');

Route::any('/{path?}', LandingContentController::class)
    ->where('path', '.*')
    ->name('landing.content');
