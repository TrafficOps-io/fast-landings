<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestampTz('verification_requested_at')->nullable();
            $table->ulid('verification_request_id')->nullable();
            $table->string('verification_operation')->nullable();
            $table->timestampTz('next_check_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['next_check_at']);
            $table->dropColumn(['verification_requested_at', 'verification_request_id', 'verification_operation', 'next_check_at']);
        });
    }
};
