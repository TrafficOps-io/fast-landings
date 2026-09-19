<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\FileWorkspaceController;
use App\Http\Controllers\LandingDownloadController;
use App\Http\Controllers\LandingPreviewController;
use App\Http\Controllers\TemplateAssetController;
use App\Http\Controllers\TemplateImageController;
use App\Http\Controllers\TemplateMediaController;
use App\Http\Controllers\TemplatePreviewController;
use App\Livewire\AiIntegrations\Index as AiIntegrationsIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Cloudflare\Index as CloudflareIndex;
use App\Livewire\Dashboard;
use App\Livewire\Domains\Index as DomainsIndex;
use App\Livewire\Files\Editor as FileEditor;
use App\Livewire\Landings\EditTemplate;
use App\Livewire\Landings\FromTemplate;
use App\Livewire\Landings\Index as LandingsIndex;
use App\Livewire\Landings\Show as LandingShow;
use App\Livewire\Templates\Index as TemplatesIndex;
use App\Livewire\Users\Index as UsersIndex;
use Illuminate\Support\Facades\Route;

Route::domain(config('fast-landings.panel_domain'))->group(function (): void {
    Route::redirect('/', '/admin');

    foreach (['register', 'forgot-password', 'reset-password/{token}', 'verify-email'] as $disabledAuthPath) {
        Route::any($disabledAuthPath, fn () => abort(404));
    }

    Route::prefix('admin')->group(function (): void {
        Route::middleware('guest')->get('/login', Login::class)->name('login');
        Route::any('/register', fn () => abort(404));
        Route::middleware('auth')->post('/logout', LogoutController::class)->name('logout');

        Route::middleware(['auth', 'active'])->group(function (): void {
            Route::get('/', Dashboard::class)->name('dashboard');
            Route::get('/landings', LandingsIndex::class)->name('landings.index');
            Route::get('/landings/from-template/{template}', FromTemplate::class)->name('landings.from-template');
            Route::get('/landings/{landing}/template', EditTemplate::class)->name('landings.edit-template');
            Route::get('/landings/{landing}', LandingShow::class)->name('landings.show');
            Route::get('/templates/{template}/image', [TemplateImageController::class, 'template'])->name('templates.image');
            Route::get('/templates/{template}/assets/{path}', TemplateAssetController::class)
                ->where('path', '.*')->name('templates.asset');
            Route::get('/templates/{template}/preview', TemplatePreviewController::class)->name('templates.preview');
            Route::get('/landing-releases/{release}/image', [TemplateImageController::class, 'release'])->name('landings.release-image');
            Route::get('/landing-releases/{release}/preview', LandingPreviewController::class)->name('landings.preview');
            Route::get('/landing-releases/{release}/download', LandingDownloadController::class)->name('landings.download');
            Route::get('/templates', TemplatesIndex::class)->name('templates.index');
            Route::get('/templates/{template}/files', FileEditor::class)->middleware('administrator')->name('templates.files');
            Route::get('/landing-releases/{release}/files', FileEditor::class)->name('landings.files');
            Route::get('/file-workspaces/{workspace}/file', [FileWorkspaceController::class, 'show'])->name('files.show');
            Route::get('/file-workspaces/{workspace}/download', [FileWorkspaceController::class, 'download'])->name('files.download');
            Route::get('/template-media/{filename}', TemplateMediaController::class)
                ->where('filename', '[A-Za-z0-9]+\.(?:jpe?g|png|gif|webp|avif)')->name('templates.media');
            Route::get('/domains', DomainsIndex::class)->name('domains.index');
        });

        Route::middleware(['auth', 'active', 'administrator'])
            ->get('/users', UsersIndex::class)
            ->name('users.index');

        Route::middleware(['auth', 'active', 'administrator'])
            ->get('/ai-integrations', AiIntegrationsIndex::class)
            ->name('ai-integrations.index');

        Route::middleware(['auth', 'active', 'administrator'])
            ->get('/cloudflare', CloudflareIndex::class)
            ->name('cloudflare.index');
    });
});
