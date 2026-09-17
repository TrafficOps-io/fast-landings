<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_generations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('landing_template_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ai_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('definition_hash', 64);
            $table->json('options');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'landing_template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_generations');
    }
};
