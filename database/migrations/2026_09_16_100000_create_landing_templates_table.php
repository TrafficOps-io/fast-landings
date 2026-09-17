<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('definition');
            $table->json('asset_paths');
            $table->string('storage_path');
            $table->string('original_name');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('landings', function (Blueprint $table): void {
            $table->foreignUlid('landing_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('template_values')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('landing_template_id');
            $table->dropColumn('template_values');
        });
        Schema::dropIfExists('landing_templates');
    }
};
