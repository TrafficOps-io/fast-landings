<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsurePanelHost;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::group([], base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Public PHP forms receive their original values, including whitespace
        // and empty strings, when multipart input is forwarded to PHP-FPM.
        $landingRequest = fn (Request $request): bool => strtolower(rtrim($request->getHost(), '.')) !== config('fast-landings.panel_domain');
        $middleware->trimStrings(except: [$landingRequest]);
        $middleware->convertEmptyStringsToNull(except: [$landingRequest]);

        if (filled(env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: (string) env('TRUSTED_PROXIES'));
        }

        $middleware->prependToPriorityList(Authenticate::class, EnsurePanelHost::class);

        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'administrator' => EnsureAdministrator::class,
            'panel-host' => EnsurePanelHost::class,
        ]);
    })
    ->withCommands()
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
