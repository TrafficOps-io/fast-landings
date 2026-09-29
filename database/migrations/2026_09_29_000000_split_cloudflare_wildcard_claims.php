<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $domains = DB::table('domains')->where('provider', 'cloudflare')->where('dns_scope', 'wildcard')
            ->whereNotNull('cloudflare_domain_id')->get();
        foreach ($domains as $domain) {
            $hostname = '*.'.$domain->hostname;
            if (DB::table('cloudflare_domains')->where('hostname', $hostname)->exists()) {
                throw new RuntimeException("A Cloudflare claim already reserves [$hostname]; resolve it before migrating wildcard domains.");
            }
        }
        // Laravel rebuilds SQLite tables for foreign keys and loses partial-index predicates.
        DB::statement('DROP INDEX domains_one_primary_per_landing');
        Schema::table('domains', function (Blueprint $table) {
            $table->foreignUlid('cloudflare_wildcard_domain_id')->nullable()->unique()
                ->constrained('cloudflare_domains')->nullOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_landing ON domains (landing_id) WHERE is_primary AND landing_id IS NOT NULL');

        // The Cloudflare module reserves apex and wildcard hostnames independently.
        // Transfer existing record rows so ownership and remote IDs survive; this
        // migration performs no DNS writes.
        DB::transaction(function () use ($domains) {
            foreach ($domains as $domain) {
                $apex = DB::table('cloudflare_domains')->find($domain->cloudflare_domain_id);
                $hostname = '*.'.$domain->hostname;
                $wildcardId = (string) Str::ulid();
                DB::table('cloudflare_domains')->insert([
                    ...(array) $apex, 'id' => $wildcardId, 'hostname' => $hostname, 'kind' => 'wildcard',
                ]);
                DB::table('cloudflare_domain_records')->where('domain_id', $apex->id)
                    ->where('name', $hostname)->update(['domain_id' => $wildcardId]);
                DB::table('domains')->where('id', $domain->id)->update(['cloudflare_wildcard_domain_id' => $wildcardId]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (DB::table('domains')->whereNotNull('cloudflare_wildcard_domain_id')->get() as $domain) {
                if ($domain->cloudflare_domain_id !== null) {
                    DB::table('cloudflare_domain_records')->where('domain_id', $domain->cloudflare_wildcard_domain_id)
                        ->update(['domain_id' => $domain->cloudflare_domain_id]);
                }
                DB::table('cloudflare_domains')->where('id', $domain->cloudflare_wildcard_domain_id)->delete();
            }
        });
        DB::statement('DROP INDEX domains_one_primary_per_landing');
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique(['cloudflare_wildcard_domain_id']);
            $table->dropConstrainedForeignId('cloudflare_wildcard_domain_id');
        });
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_landing ON domains (landing_id) WHERE is_primary AND landing_id IS NOT NULL');
    }
};
