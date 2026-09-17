<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds the table to add the foreign key and otherwise loses
        // the partial predicate when Laravel reconstructs this custom index.
        DB::statement('DROP INDEX domains_one_primary_per_landing');
        Schema::table('domains', function (Blueprint $table) {
            $table->string('dns_scope')->default('exact');
            $table->foreignUlid('parent_domain_id')->nullable()->constrained('domains')->restrictOnDelete();
        });
        $this->restorePrimaryIndex();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX domains_one_primary_per_landing');
        Schema::table('domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_domain_id');
            $table->dropColumn('dns_scope');
        });
        $this->restorePrimaryIndex();
    }

    private function restorePrimaryIndex(): void
    {
        DB::statement('CREATE UNIQUE INDEX domains_one_primary_per_landing ON domains (landing_id) WHERE is_primary AND landing_id IS NOT NULL');
    }
};
