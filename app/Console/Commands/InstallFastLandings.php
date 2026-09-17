<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Installation;
use App\Models\User;
use App\Support\DnsTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use TrafficOps\Cloudflare\Support\Hostname;

class InstallFastLandings extends Command
{
    protected $signature = 'fast-landings:install
        {--system-domain= : Base domain for generated system subdomains}
        {--domain= : Deprecated alias for --system-domain}
        {--origin= : Public server IPv4/IPv6 address (A/AAAA) or hostname (CNAME)}
        {--admin-name=Administrator : Initial administrator name}
        {--admin-email= : Initial administrator email}
        {--admin-password= : Initial administrator password (prefer FAST_LANDINGS_ADMIN_PASSWORD)}
        {--force : Update an existing installation and administrator}';

    protected $description = 'Configure the installation and create the first administrator';

    public function handle(): int
    {
        try {
            $domain = Hostname::normalize((string) ($this->option('system-domain') ?: $this->option('domain') ?: config('fast-landings.system_domain')));
            $origin = DnsTarget::normalize((string) ($this->option('origin') ?: config('fast-landings.origin_target')));
        } catch (\Throwable) {
            $this->error('Provide a valid system hostname and an origin IPv4/IPv6 address or hostname, without a protocol, port, wildcard, or path.');

            return self::FAILURE;
        }

        $email = Str::lower((string) ($this->option('admin-email') ?: $this->ask('Administrator email')));
        $passwordFromEnvironment = getenv('FAST_LANDINGS_ADMIN_PASSWORD');
        $password = (string) ($this->option('admin-password') ?: ($passwordFromEnvironment !== false ? $passwordFromEnvironment : null) ?: $this->secret('Administrator password'));
        $name = trim((string) $this->option('admin-name'));

        $validator = Validator::make(compact('email', 'password', 'name'), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ((Installation::query()->exists() || User::query()->where('role', UserRole::Administrator)->exists()) && ! $this->option('force')) {
            $this->error('Fast Landings is already installed. Use --force only when intentionally changing its administrator.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($domain, $origin, $email, $password, $name): void {
            $installation = Installation::query()->first() ?? new Installation;
            $installation->fill(['name' => 'Fast Landings', 'domain' => $domain, 'origin_target' => $origin])->save();

            $administrator = User::query()->firstOrNew(['email' => $email]);
            $administrator->fill([
                'name' => $name,
                'password' => Hash::make($password),
                'role' => UserRole::Administrator,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();
        });

        $panelDomain = (string) config('fast-landings.panel_domain');
        $panelUrl = app()->environment('local')
            ? rtrim((string) config('app.url'), '/')
            : "https://{$panelDomain}";
        $this->info("Fast Landings is ready at {$panelUrl}/admin.");

        return self::SUCCESS;
    }
}
