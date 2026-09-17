<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallFastLandingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_install_creates_the_installation_and_initial_administrator(): void
    {
        $this->artisan('fast-landings:install', [
            '--system-domain' => 'Go.Example.COM.',
            '--origin' => 'Origin.Example.COM.',
            '--admin-name' => 'Primary Administrator',
            '--admin-email' => 'ADMIN@EXAMPLE.TEST',
            '--admin-password' => 'a-secure-password',
        ])
            ->expectsOutput('Fast Landings is ready at https://fast-landings.test/admin.')
            ->assertSuccessful();

        $installation = Installation::query()->sole();
        $this->assertSame('Fast Landings', $installation->name);
        $this->assertSame('go.example.com', $installation->domain);
        $this->assertSame('origin.example.com', $installation->origin_target);

        $administrator = User::query()->sole();
        $this->assertSame('Primary Administrator', $administrator->name);
        $this->assertSame('admin@example.test', $administrator->email);
        $this->assertSame(UserRole::Administrator, $administrator->role);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue(Hash::check('a-secure-password', $administrator->password));
    }

    public function test_install_refuses_to_overwrite_existing_configuration_without_force(): void
    {
        $installation = Installation::query()->create([
            'name' => 'Fast Landings',
            'domain' => 'original.example.test',
            'origin_target' => 'origin.example.test',
        ]);
        $administrator = User::factory()->create([
            'name' => 'Original Administrator',
            'email' => 'original@example.test',
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);

        $this->artisan('fast-landings:install', [
            '--domain' => 'replacement.example.test',
            '--origin' => 'new-origin.example.test',
            '--admin-name' => 'Replacement Administrator',
            '--admin-email' => 'replacement@example.test',
            '--admin-password' => 'replacement-password',
        ])
            ->expectsOutput('Fast Landings is already installed. Use --force only when intentionally changing its administrator.')
            ->assertFailed();

        $this->assertSame(1, Installation::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame('original.example.test', $installation->fresh()->domain);
        $this->assertSame('Original Administrator', $administrator->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'replacement@example.test']);
    }

    public function test_unattended_install_reads_password_from_environment(): void
    {
        putenv('FAST_LANDINGS_ADMIN_PASSWORD=environment-password');

        try {
            $this->artisan('fast-landings:install', [
                '--system-domain' => 'go.example.test',
                '--origin' => 'origin.example.test',
                '--admin-email' => 'unattended@example.test',
            ])->assertSuccessful();
        } finally {
            putenv('FAST_LANDINGS_ADMIN_PASSWORD');
        }

        $this->assertTrue(Hash::check('environment-password', User::query()->sole()->password));
    }

    #[DataProvider('ipOrigins')]
    public function test_install_accepts_a_public_server_ip_as_the_origin(string $origin, string $expected): void
    {
        $this->artisan('fast-landings:install', [
            '--system-domain' => 'go.example.test',
            '--origin' => $origin,
            '--admin-email' => 'admin@example.test',
            '--admin-password' => 'a-secure-password',
        ])->assertSuccessful();

        $this->assertSame($expected, Installation::query()->sole()->origin_target);
    }

    public static function ipOrigins(): array
    {
        return [
            'IPv4' => ['203.0.113.10', '203.0.113.10'],
            'IPv6' => ['2001:0DB8:0:0:0:0:0:10', '2001:db8::10'],
        ];
    }

    public function test_install_accepts_an_ip_origin_from_configuration(): void
    {
        config(['fast-landings.origin_target' => '203.0.113.20']);

        $this->artisan('fast-landings:install', [
            '--system-domain' => 'go.example.test',
            '--admin-email' => 'admin@example.test',
            '--admin-password' => 'a-secure-password',
        ])->assertSuccessful();

        $this->assertSame('203.0.113.20', Installation::query()->sole()->origin_target);
    }

    #[DataProvider('invalidOrigins')]
    public function test_install_rejects_malformed_origin_targets(string $origin): void
    {
        $this->artisan('fast-landings:install', [
            '--system-domain' => 'go.example.test',
            '--origin' => $origin,
            '--admin-email' => 'admin@example.test',
            '--admin-password' => 'a-secure-password',
        ])
            ->expectsOutput('Provide a valid system hostname and an origin IPv4/IPv6 address or hostname, without a protocol, port, wildcard, or path.')
            ->assertFailed();

        $this->assertDatabaseCount('installations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public static function invalidOrigins(): array
    {
        return [
            'invalid IPv4' => ['999.0.113.10'],
            'ambiguous IPv4' => ['192.000.2.1'],
            'invalid IPv6' => ['2001:db8:::10'],
            'bracketed IPv6' => ['[2001:db8::10]'],
            'scoped IPv6' => ['fe80::1%eth0'],
            'URL' => ['https://origin.example.test'],
            'port' => ['origin.example.test:443'],
            'network range' => ['203.0.113.0/24'],
        ];
    }
}
