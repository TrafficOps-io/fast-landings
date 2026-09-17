<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelHost
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            strtolower(rtrim($request->getHost(), '.')) === config('fast-landings.panel_domain'),
            404,
        );

        return $next($request);
    }
}
