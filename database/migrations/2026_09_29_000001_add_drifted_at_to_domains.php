<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', fn (Blueprint $table) => $table->timestampTz('drifted_at')->nullable());
        DB::table('domains')->where('status', 'drifted')->update(['drifted_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('domains', fn (Blueprint $table) => $table->dropColumn('drifted_at'));
    }
};
