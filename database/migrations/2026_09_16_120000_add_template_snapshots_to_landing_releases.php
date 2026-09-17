<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropSqliteActiveIndex();
        Schema::table('landing_releases', function (Blueprint $table): void {
            $table->foreignUlid('landing_template_id')->nullable()->constrained()->nullOnDelete();
            $table->json('template_values')->nullable();
        });
        $this->restoreSqliteActiveIndex();

        // Only the active release has known settings in installations predating snapshots.
        DB::table('landings')->whereNotNull('template_values')->orderBy('id')->chunkById(100, function ($landings): void {
            foreach ($landings as $landing) {
                DB::table('landing_releases')->where('landing_id', $landing->id)->where('is_active', true)->update([
                    'landing_template_id' => $landing->landing_template_id,
                    'template_values' => $landing->template_values,
                ]);
            }
        });
    }

    public function down(): void
    {
        $this->dropSqliteActiveIndex();
        Schema::table('landing_releases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('landing_template_id');
            $table->dropColumn('template_values');
        });
        $this->restoreSqliteActiveIndex();
    }

    // SQLite table rebuilds do not preserve partial-index predicates in Laravel.
    private function dropSqliteActiveIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX landing_releases_one_active_per_landing');
        }
    }

    private function restoreSqliteActiveIndex(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX landing_releases_one_active_per_landing ON landing_releases (landing_id) WHERE is_active');
        }
    }
};
