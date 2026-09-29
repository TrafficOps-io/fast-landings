<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A domain is verified once it has been Active at least once (ADR-0003).
     * Serving gates on this marker instead of the latest check status, so
     * domains that are Active today are backfilled as verified.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->timestampTz('verified_at')->nullable();
        });

        DB::table('domains')->where('status', 'active')->update(['verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
