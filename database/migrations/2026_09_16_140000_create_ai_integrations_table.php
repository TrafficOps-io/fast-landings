<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_integrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('installation_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('provider', 40);
            $table->text('api_key');
            $table->string('api_url', 2048)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_integrations');
    }
};
