<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->default('Fast Landings');
            $table->string('domain', 253)->unique();
            $table->string('origin_target', 253);
            $table->timestampsTz();
        });

        Schema::create('landings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('landing_releases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('landing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('entrypoint')->default('index.html');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('file_count');
            $table->char('checksum', 64);
            $table->boolean('is_active')->default(false);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->index(['landing_id', 'is_active']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('landing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('system_subdomain', 63)->nullable()->unique();
            $table->string('kind');
            $table->string('provider');
            $table->string('status')->default('pending');
            $table->boolean('is_primary')->default(false);
            $table->string('dns_target', 253)->nullable();
            $table->string('verification_token', 64)->nullable();
            $table->ulid('cloudflare_domain_id')->nullable()->unique();
            $table->foreign('cloudflare_domain_id')->references('id')->on('cloudflare_domains')->nullOnDelete();
            $table->timestampTz('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['landing_id', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX landing_releases_one_active_per_landing ON landing_releases (landing_id) WHERE is_active');
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_landing ON domains (landing_id) WHERE is_primary AND landing_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('landing_releases');
        Schema::dropIfExists('landings');
        Schema::dropIfExists('installations');
    }
};
