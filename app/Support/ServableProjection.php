<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * The rows deploy/projection-sync.php publishes as host symlinks: every servable
 * domain (Domain::scopeServable) joined with its landing's active release.
 */
final class ServableProjection
{
    /** @return Collection<int, object{hostname: string, landing_id: string, release_id: string, storage_path: string}> */
    public static function rows(): Collection
    {
        return Domain::query()
            ->servable()
            ->join('landings', 'landings.id', '=', 'domains.landing_id')
            ->join('landing_releases as releases', fn (JoinClause $join) => $join
                ->on('releases.landing_id', '=', 'landings.id')
                ->where('releases.is_active', true))
            ->orderByDesc('releases.activated_at')
            ->toBase()
            ->get([
                'domains.hostname',
                'landings.id as landing_id',
                'releases.id as release_id',
                'releases.storage_path',
            ]);
    }
}
