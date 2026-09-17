<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdministrator;
use App\Services\Templates\FastLandingsTemplateDialect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use TrafficOps\TemplateDsl\TemplateDialect;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateFieldTypes;
use TrafficOps\TemplateDsl\TemplateMarkupCompiler;
use TrafficOps\TemplateDsl\TemplateRichText;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FastLandingsTemplateDialect::class);
        $this->app->singleton(TemplateDialect::class, fn ($app) => $app->make(FastLandingsTemplateDialect::class));
        $this->app->singleton(TemplateFieldTypes::class);
        $this->app->singleton(TemplateRichText::class);
        $this->app->singleton(TemplateMarkupCompiler::class, fn () => new TemplateMarkupCompiler(
            (int) config('fast-landings.templates.max_definition_bytes', 2 * 1024 * 1024),
        ));
        $this->app->singleton(TemplateSourceParser::class, fn ($app) => new TemplateSourceParser(
            $app->make(TemplateFieldTypes::class),
            $app->make(TemplateMarkupCompiler::class),
            $app->make(TemplateDialect::class),
            (int) config('fast-landings.templates.max_definition_bytes', 2 * 1024 * 1024),
        ));
        $this->app->singleton(TemplateEngine::class, fn ($app) => new TemplateEngine(
            $app->make(TemplateRichText::class),
            fn (): int => (int) config('fast-landings.templates.max_render_bytes', 8 * 1024 * 1024),
            $app->make(TemplateDialect::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $panelDomain = (string) config('fast-landings.panel_domain');

        Livewire::setUpdateRoute(
            fn ($handle, $path) => Route::post($path, $handle)
                ->domain($panelDomain)
                ->name('panel.')
        );

        Livewire::addPersistentMiddleware([
            EnsureActiveUser::class,
            EnsureAdministrator::class,
        ]);

        $this->app->booted(function () use ($panelDomain): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (str_starts_with(ltrim($route->uri(), '/'), 'livewire-')) {
                    $route->domain($panelDomain);
                }
            }
        });
    }
}
