<?php

namespace App\Services;

use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Jobs\CheckDomain;
use App\Jobs\ProvisionCloudflareDomain;
use App\Models\Domain;
use App\Support\DomainError;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class DomainChecks
{
    public function requestCheck(Domain $domain): void
    {
        $this->request($domain, false);
    }

    public function requestProvision(Domain $domain): void
    {
        $this->request($domain->dnsSource(), true);
    }

    public function pending(string $domainId, string $requestId): ?Domain
    {
        return Domain::query()->where('verification_request_id', $requestId)->find($domainId);
    }

    public function complete(Domain $domain, string $requestId, bool $immediate = false): void
    {
        Domain::query()->whereKey($domain->getKey())->where('verification_request_id', $requestId)->update([
            'verification_requested_at' => null,
            'verification_request_id' => null,
            'verification_operation' => null,
            'next_check_at' => $immediate ? now() : now()->addSeconds($this->interval($domain)),
        ]);

        // The package verifies a complete integration in one request. Reuse those
        // statuses rather than checking every sibling domain again next minute.
        if (! $immediate && $domain->provider === DomainProvider::Cloudflare) {
            $integrationId = $domain->cloudflareDomain?->zone?->account?->integration_id;
            if ($integrationId !== null) {
                Domain::query()->where('provider', DomainProvider::Cloudflare)
                    ->whereNull('verification_request_id')
                    ->where(fn ($query) => $query->whereNull('verification_operation')->orWhere('verification_operation', '!=', 'provision'))
                    ->whereHas('cloudflareDomain.zone.account', fn ($query) => $query->where('integration_id', $integrationId))
                    ->eachById(function (Domain $sibling): void {
                        Domain::query()->whereKey($sibling->getKey())->whereNull('verification_request_id')
                            ->where(fn ($query) => $query->whereNull('verification_operation')->orWhere('verification_operation', '!=', 'provision'))
                            ->update([
                                'next_check_at' => now()->addSeconds($this->interval($sibling)),
                            ]);
                    });
            }
        }
    }

    public function failed(string $domainId, string $requestId, ?Throwable $exception = null): void
    {
        DB::transaction(function () use ($domainId, $requestId, $exception): void {
            $domain = Domain::query()->where('verification_request_id', $requestId)->lockForUpdate()->find($domainId);
            if ($domain === null) {
                return;
            }

            $message = $exception !== null
                ? DomainError::message($exception)
                : 'Domain verification failed. Retry the check or review the queue worker logs.';
            // The manager may already have recorded a useful provider error for
            // this request before the queue wraps it in a generic final failure.
            if ($domain->last_error && $domain->verification_requested_at !== null
                && $domain->last_checked_at?->greaterThanOrEqualTo($domain->verification_requested_at)) {
                $message = DomainError::display($domain->last_error);
            }

            $domain->transitionTo(DomainStatus::Error, [
                'last_checked_at' => now(),
                'last_error' => $message,
                'verification_requested_at' => null,
                'verification_request_id' => null,
                'next_check_at' => now()->addSeconds((int) config('fast-landings.domain_checks.error_interval', 300)),
            ]);
            if ($domain->dns_scope === 'wildcard') {
                Domain::transition($domain->subdomains(), DomainStatus::Error, [
                    'last_checked_at' => now(),
                    'last_error' => 'Verify wildcard DNS for ['.$domain->hostname.'] first.',
                ]);
            }
        });
    }

    private function request(Domain $domain, bool $provision): void
    {
        $requestId = (string) Str::ulid();

        try {
            DB::transaction(function () use ($domain, $provision, $requestId): void {
                $locked = Domain::query()->lockForUpdate()->find($domain->getKey());
                if ($locked === null || $locked->provider === DomainProvider::System) {
                    return;
                }
                // A stalled provisioning worker must be replaced by provisioning,
                // not by a read-only check of DNS records that do not exist yet.
                $provision = $provision || $locked->verification_operation === 'provision';
                if ($provision && $locked->provider !== DomainProvider::Cloudflare) {
                    throw new LogicException('Only Cloudflare domains can be provisioned.');
                }

                $lease = max(300, (int) config('fast-landings.domain_checks.request_lease', 600));
                if ($locked->verification_requested_at?->gt(now()->subSeconds($lease))) {
                    return;
                }

                $locked->update([
                    'verification_requested_at' => now(),
                    'verification_request_id' => $requestId,
                    'verification_operation' => $provision ? 'provision' : 'check',
                    'next_check_at' => now()->addSeconds($lease),
                ]);

                $job = $provision
                    ? new ProvisionCloudflareDomain((string) $locked->getKey(), $requestId)
                    : new CheckDomain((string) $locked->getKey(), $requestId);

                // Verification never falls back to DNS/API I/O in an HTTP request.
                $connection = (string) config('queue.default', 'database');
                if (in_array(config('queue.connections.'.$connection.'.driver'), ['sync', 'null', 'deferred', 'background', 'failover'], true)) {
                    $connection = 'database';
                }
                Bus::dispatch($job->onConnection($connection)->afterCommit());
            });
        } catch (Throwable $exception) {
            Domain::query()->whereKey($domain->getKey())->where('verification_request_id', $requestId)->update([
                'verification_requested_at' => null,
                'verification_request_id' => null,
                'next_check_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function interval(Domain $domain): int
    {
        $setting = match ($domain->status) {
            DomainStatus::Active => 'active_interval',
            DomainStatus::Error, DomainStatus::Unreachable => 'error_interval',
            default => 'pending_interval',
        };

        return max(60, (int) config('fast-landings.domain_checks.'.$setting, 60));
    }
}
