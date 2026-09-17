<?php

namespace App\Http\Controllers;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaddyAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $expected = (string) config('fast-landings.caddy_ask_token');
        $provided = (string) $request->query('token');
        abort_if($expected === '' || ! hash_equals($expected, $provided), 403);

        $domain = strtolower(rtrim((string) $request->query('domain'), '.'));
        $routable = $domain !== '' && Domain::query()
            ->where('hostname', $domain)
            ->where('status', DomainStatus::Active)
            ->whereHas('landing', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('releases', fn ($releases) => $releases->where('is_active', true)))
            ->exists();
        abort_unless($routable, 404);

        return response('', 200);
    }
}
