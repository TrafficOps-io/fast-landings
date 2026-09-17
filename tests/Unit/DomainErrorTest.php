<?php

namespace Tests\Unit;

use App\Support\DomainError;
use Illuminate\Contracts\Cache\LockTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareConflictException;
use TrafficOps\Cloudflare\Exceptions\CloudflareException;
use TrafficOps\Cloudflare\Exceptions\CloudflareNotFoundException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;

class DomainErrorTest extends TestCase
{
    #[DataProvider('providerFailures')]
    public function test_provider_failures_offer_recovery_guidance_without_exposing_upstream_details(Throwable $exception, string $guidance): void
    {
        $message = DomainError::message($exception);
        $this->assertStringContainsString($guidance, $message);
        $this->assertStringNotContainsString('private-token', $message);
        $this->assertStringNotContainsString('/var/private', $message);
        $this->assertSame($message, DomainError::display($message));
    }

    public static function providerFailures(): array
    {
        $secret = 'Bearer private-token; server /var/private/credentials';

        return [
            'invalid token' => [new CloudflareAuthenticationException($secret), 'replace it in Cloudflare connections'],
            'scope' => [new CloudflarePermissionException($secret), 'Zone → DNS → Edit'],
            'rate limit' => [new CloudflareRateLimitException($secret, 120), 'Try again in 120 seconds'],
            'invalid retry delay' => [new CloudflareRateLimitException($secret, -1), 'Try again in 1 second'],
            'network or DNS resolver' => [new CloudflareTransportException($secret), 'Check outbound internet access'],
            'missing resource' => [new CloudflareNotFoundException($secret), 'Refresh the connection'],
            'invalid configuration' => [new CloudflareValidationException($secret), 'hostname belongs to the selected zone'],
            'unknown provider error' => [new CloudflareException($secret), 'check its token permissions'],
            'unknown internal error' => [new RuntimeException($secret), 'review the server logs'],
            'unknown conflict' => [new CloudflareConflictException($secret), 'already registered in Domains'],
            'conflicting record' => [new CloudflareConflictException('Cloudflare already contains a conflicting A record for [private-token.example.com].'), 'The conflicting record was not overwritten'],
            'domain claim' => [new CloudflareConflictException('Domain claim [private-token.example.com] conflicts with [other.example.com].'), 'use its existing assignment'],
            'connection busy' => [new LockTimeoutException($secret), 'Wait a moment'],
            'wrapped lock' => [new CloudflareConflictException($secret, previous: new LockTimeoutException($secret)), 'Wait a moment'],
            'global claim lock' => [new CloudflareConflictException('Could not acquire the global domain claim lock.'), 'Another domain is being added'],
        ];
    }

    public function test_unknown_previous_exception_contents_are_not_exposed(): void
    {
        $exception = new CloudflareTransportException('private-token', previous: new RuntimeException('Authorization: Bearer private-token'));
        $this->assertStringNotContainsString('private-token', DomainError::message($exception));
    }

    public function test_blank_stored_diagnostics_are_not_displayed(): void
    {
        $this->assertNull(DomainError::display(null));
        $this->assertNull(DomainError::display(''));
        $this->assertNull(DomainError::display('   '));
    }

    #[DataProvider('unsafeSavedDiagnostics')]
    public function test_unknown_or_modified_stored_messages_use_generic_guidance(string $saved): void
    {
        $this->assertSame('The last check failed. Run Check now to refresh the diagnostic.', DomainError::display($saved));
    }

    public static function unsafeSavedDiagnostics(): array
    {
        return [
            ['Authorization: Bearer private-token'],
            ['<script>private-token</script>'],
            ["Cloudflare is limiting requests. Try again in 10 seconds.\nprivate-token"],
            ['Cloudflare is limiting requests. Try again in private-token seconds.'],
            ["Public DNS is missing for [example.com].\nprivate-token"],
            [DomainError::message(new CloudflareAuthenticationException('')).' private-token'],
        ];
    }

    public function test_existing_dns_formats_are_normalized_without_echoing_variable_text(): void
    {
        $this->assertStringContainsString('Compare the DNS type and target', DomainError::display('Public DNS is missing for [private-token].'));
        $this->assertStringNotContainsString('private-token', DomainError::display('Public DNS is mismatched for [private-token].'));
        $this->assertSame('Verify the parent domain’s wildcard DNS first, then check this subdomain.', DomainError::display('Verify wildcard DNS for [private-token] first.'));
        $this->assertStringContainsString('Refresh the connection', DomainError::display('The Cloudflare domain is missing from its integration check.'));
    }
}
