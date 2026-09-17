<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_releases', function (Blueprint $table): void {
            $table->string('preview_status', 20)->nullable()->index();
            $table->ulid('preview_token')->nullable();
            $table->string('preview_path')->nullable();
            $table->timestamp('preview_requested_at')->nullable();
            $table->timestamp('preview_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landing_releases', function (Blueprint $table): void {
            $table->dropIndex(['preview_status']);
            $table->dropColumn(['preview_status', 'preview_token', 'preview_path', 'preview_requested_at', 'preview_generated_at']);
        });
    }
};
