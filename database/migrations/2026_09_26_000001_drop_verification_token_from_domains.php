<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DNS pointing at the origin target is the only proof of hostname control
     * (ADR-0002). The column was a leftover of the rejected TXT design and was
     * never written with a value.
     */
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('verification_token', 64)->nullable();
        });
    }
};
