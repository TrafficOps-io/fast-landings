<?php

namespace App\Console\Commands;

use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Services\DomainChecks;
use Illuminate\Console\Command;

class CheckDomains extends Command
{
    protected $signature = 'fast-landings:check-domains';

    protected $description = 'Queue due manual DNS and Cloudflare domain checks';

    public function handle(DomainChecks $checks): int
    {
        $integrations = [];

        Domain::query()->where('provider', DomainProvider::Cloudflare)
            ->where('verification_requested_at', '>', now()->subSeconds(max(300, (int) config('fast-landings.domain_checks.request_lease', 600))))
            ->with('cloudflareDomain.zone.account')
            ->eachById(function (Domain $domain) use (&$integrations): void {
                $integrationId = $domain->cloudflareDomain?->zone?->account?->integration_id;
                if ($integrationId !== null) {
                    $integrations[$integrationId] = true;
                }
            });

        Domain::query()->whereIn('provider', [DomainProvider::Dns, DomainProvider::Cloudflare])
            ->where(function ($query): void {
                $query->where('next_check_at', '<=', now())
                    ->orWhere(function ($query): void {
                        $query->whereNull('next_check_at')
                            ->where(function ($query): void {
                                $query->whereNull('last_checked_at')
                                    ->orWhere(function ($query): void {
                                        $query->where('status', DomainStatus::Active)
                                            ->where('last_checked_at', '<=', now()->subSeconds((int) config('fast-landings.domain_checks.active_interval', 600)));
                                    })
                                    ->orWhere(function ($query): void {
                                        $query->where('status', '!=', DomainStatus::Active)
                                            ->where('last_checked_at', '<=', now()->subSeconds((int) config('fast-landings.domain_checks.pending_interval', 60)));
                                    });
                            });
                    });
            })
            ->with('cloudflareDomain.zone.account')
            ->eachById(function (Domain $domain) use ($checks, &$integrations): void {
                if ($domain->provider === DomainProvider::Cloudflare) {
                    $integrationId = $domain->cloudflareDomain?->zone?->account?->integration_id;
                    if ($integrationId !== null) {
                        // Provisioning writes belong to an individual domain;
                        // only read-only integration checks can be coalesced.
                        if ($domain->verification_operation !== 'provision' && isset($integrations[$integrationId])) {
                            return;
                        }
                        $integrations[$integrationId] = true;
                    }
                }

                $checks->requestCheck($domain);
            });

        $this->info('Due domain checks queued.');

        return self::SUCCESS;
    }
}
