<?php

namespace App\Jobs;

use App\Enums\DomainProvider;
use App\Models\Domain;
use App\Services\DomainChecks;
use App\Services\DomainManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class CheckDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public string $domainId, public string $requestId) {}

    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('fast-landings:domain-work:'.$this->domainId))
            ->shared()->releaseAfter(15)->expireAfter(90)];
    }

    public function handle(DomainManager $manager, DomainChecks $checks): void
    {
        $domain = $checks->pending($this->domainId, $this->requestId);
        if ($domain === null) {
            return;
        }

        try {
            $checked = match ($domain->provider) {
                DomainProvider::Dns => $manager->checkDns($domain),
                DomainProvider::Cloudflare => $manager->checkCloudflare($domain),
                default => $domain,
            };

            $checks->complete($checked, $this->requestId);
        } catch (ModelNotFoundException $exception) {
            if (Domain::query()->whereKey($this->domainId)->exists()) {
                throw $exception;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DomainChecks::class)->failed($this->domainId, $this->requestId, $exception);
    }
}
