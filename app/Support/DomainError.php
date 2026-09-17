<?php

namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareConflictException;
use TrafficOps\Cloudflare\Exceptions\CloudflareException;
use TrafficOps\Cloudflare\Exceptions\CloudflareNotFoundException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;

final class DomainError
{
    private const MESSAGES = [
        'busy' => 'Another operation is using this Cloudflare connection. Wait a moment, then retry.',
        'authentication' => 'The Cloudflare token is expired, revoked, or invalid. Ask an administrator to replace it in Cloudflare connections, then retry.',
        'permission' => 'The Cloudflare token cannot access this zone or its DNS records. An administrator must grant Zone → Zone → Read and Zone → DNS → Edit for this zone, then refresh the connection and retry.',
        'transport' => 'This server could not reach Cloudflare or the public DNS resolver. Check outbound internet access, then retry.',
        'missing' => 'The Cloudflare connection, zone, or DNS record is no longer available. Refresh the connection and check that its token still has access to this zone, then retry.',
        'validation' => 'Cloudflare rejected the DNS configuration. Check that the hostname belongs to the selected zone and that the DNS type and target match the instructions, then retry.',
        'cloudflare' => 'Cloudflare could not complete this operation. Refresh the connection, check its token permissions and DNS configuration, then retry.',
        'unknown' => 'The domain operation could not be completed. Retry; if it keeps failing, ask the administrator to review the server logs and domain configuration.',
        'record_conflict' => 'A conflicting DNS record already exists for this hostname in Cloudflare. Compare its type and target with the DNS instructions, resolve the conflicting record, then retry DNS setup. The conflicting record was not overwritten.',
        'claim_conflict' => 'This hostname conflicts with a domain already registered in this installation. Find that domain in Domains and use its existing assignment, or choose a different hostname.',
        'claim_busy' => 'Another domain is being added. Wait a moment, then retry.',
        'conflict' => 'Cloudflare reported a conflict. Review this hostname’s DNS records in Cloudflare and check whether it is already registered in Domains, then retry DNS setup.',
        'dns_mismatch' => 'Public DNS does not match the expected record yet. Compare the DNS type and target with the instructions, allow time for propagation, then check again.',
        'parent_dns' => 'Verify the parent domain’s wildcard DNS first, then check this subdomain.',
        'legacy' => 'The last check failed. Run Check now to refresh the diagnostic.',
    ];

    /**
     * Public, actionable status text. Provider exception messages and previous
     * exceptions may contain API response bodies, credentials, or server paths.
     * Never copy those values into a domain status returned to the browser.
     */
    public static function message(Throwable $exception): string
    {
        if ($exception instanceof LockTimeoutException
            || ($exception instanceof CloudflareConflictException && $exception->getPrevious() instanceof LockTimeoutException)) {
            return self::MESSAGES['busy'];
        }

        if ($exception instanceof CloudflareRateLimitException) {
            $delay = max(1, $exception->retryAfter);

            return 'Cloudflare is limiting requests. Try again in '.$delay.($delay === 1 ? ' second.' : ' seconds.');
        }

        return match (true) {
            $exception instanceof CloudflareAuthenticationException => self::MESSAGES['authentication'],
            $exception instanceof CloudflarePermissionException => self::MESSAGES['permission'],
            $exception instanceof CloudflareTransportException => self::MESSAGES['transport'],
            $exception instanceof CloudflareConflictException => self::conflictMessage($exception),
            $exception instanceof CloudflareNotFoundException => self::MESSAGES['missing'],
            $exception instanceof CloudflareValidationException => self::MESSAGES['validation'],
            $exception instanceof CloudflareException => self::MESSAGES['cloudflare'],
            default => self::MESSAGES['unknown'],
        };
    }

    /** Only display known safe diagnostics; historical rows may contain raw API errors. */
    public static function display(?string $saved): ?string
    {
        if ($saved === null || trim($saved) === '') {
            return null;
        }
        if (in_array($saved, self::MESSAGES, true)
            || preg_match('/\ACloudflare is limiting requests\. Try again in [1-9][0-9]{0,18} seconds?\.\z/', $saved)) {
            return $saved;
        }

        // Recognize existing DNS-check formats but do not repeat their variable
        // contents. The domain detail already shows the trusted hostname/target.
        if (preg_match('/\APublic DNS is (?:missing|mismatched|indeterminate) for \[[^\r\n]+\]\.\z/', $saved)) {
            return self::MESSAGES['dns_mismatch'];
        }
        if (preg_match('/\AVerify wildcard DNS for \[[^\r\n]+\] first\.\z/', $saved)) {
            return self::MESSAGES['parent_dns'];
        }
        if ($saved === 'The Cloudflare domain is missing from its integration check.') {
            return self::MESSAGES['missing'];
        }

        return self::MESSAGES['legacy'];
    }

    private static function conflictMessage(CloudflareConflictException $exception): string
    {
        // These prefixes distinguish local package failures. Only fixed guidance
        // is returned, even if a provider response happens to use the same text.
        return match (true) {
            str_starts_with($exception->getMessage(), 'Cloudflare already contains a conflicting ') => self::MESSAGES['record_conflict'],
            str_starts_with($exception->getMessage(), 'Domain claim [') => self::MESSAGES['claim_conflict'],
            $exception->getMessage() === 'Could not acquire the global domain claim lock.' => self::MESSAGES['claim_busy'],
            default => self::MESSAGES['conflict'],
        };
    }
}
