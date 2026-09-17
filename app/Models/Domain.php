<?php

namespace App\Models;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Support\DnsTarget;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Support\ModelResolver;

class Domain extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => DomainKind::class,
            'provider' => DomainProvider::class,
            'status' => DomainStatus::class,
            'is_primary' => 'boolean',
            'last_checked_at' => 'immutable_datetime',
            'verification_requested_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
        ];
    }

    public function landing(): BelongsTo
    {
        return $this->belongsTo(Landing::class);
    }

    public function parentDomain(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_domain_id');
    }

    public function subdomains(): HasMany
    {
        return $this->hasMany(self::class, 'parent_domain_id');
    }

    public function dnsSource(): self
    {
        return $this->parentDomain ?? $this;
    }

    /** @return list<string> */
    public function dnsRecordNames(): array
    {
        return $this->dns_scope === 'wildcard'
            ? [$this->hostname, '*.'.$this->hostname]
            : [$this->hostname];
    }

    public function dnsScopeLabel(): string
    {
        return $this->parent_domain_id !== null
            ? __('Subdomain via wildcard')
            : ($this->dns_scope === 'wildcard' ? __('Domain + subdomains') : __('Exact hostname'));
    }

    public function dnsRecordType(): string
    {
        return DnsTarget::recordType((string) $this->dns_target);
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            DomainProvider::System => __('System subdomain'),
            DomainProvider::Dns => __('Manual DNS'),
            DomainProvider::Cloudflare => __('Cloudflare'),
        };
    }

    public function statusLabel(): string
    {
        if ($this->provider === DomainProvider::System) {
            return __('Infrastructure managed');
        }

        return match ($this->status) {
            DomainStatus::Pending => __('Awaiting DNS check'),
            DomainStatus::PendingPropagation => __('Waiting for DNS'),
            DomainStatus::Active => __('DNS verified'),
            DomainStatus::Drifted => __('DNS changed'),
            DomainStatus::Unreachable => __('Check unavailable'),
            DomainStatus::Error => __('Needs attention'),
        };
    }

    public function statusTone(): string
    {
        if ($this->provider === DomainProvider::System) {
            return 'neutral';
        }

        return match ($this->status) {
            DomainStatus::Active => 'success',
            DomainStatus::Drifted, DomainStatus::Error => 'error',
            default => 'warning',
        };
    }

    public function statusDescription(): string
    {
        if ($this->provider === DomainProvider::System) {
            return __('DNS, server routing and HTTPS for system subdomains are managed by your installation. They are not verified here.');
        }

        return match ($this->status) {
            DomainStatus::Pending => $this->parent_domain_id
                ? __('This hostname uses the connected domain’s wildcard DNS. Its own DNS check is pending; no extra record is needed unless an existing record overrides the wildcard.')
                : __('DNS has not been verified yet. Configure the required record and run a check.'),
            DomainStatus::PendingPropagation => __('Public DNS does not match the expected record yet. Check the record or allow time for propagation.'),
            DomainStatus::Active => __('DNS matches this installation. HTTPS and landing availability still depend on your server and publishing setup.'),
            DomainStatus::Drifted => __('Previously verified DNS no longer matches this installation. Restore the expected record and check again.'),
            DomainStatus::Unreachable => __('The last check could not reach DNS or Cloudflare. Retry the check and review any connection error.'),
            DomainStatus::Error => __('The last operation failed. Review the error, correct the configuration and retry.'),
        };
    }

    public function publicUrl(): string
    {
        if (! app()->environment('local')) {
            return 'https://'.$this->hostname;
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) === 'https' ? 'https' : 'http';
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $scheme.'://'.$this->hostname.($port !== null && $port !== false ? ':'.$port : '');
    }

    /** @return BelongsTo<CloudflareDomain, $this> */
    public function cloudflareDomain(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('domain'), 'cloudflare_domain_id');
    }
}
