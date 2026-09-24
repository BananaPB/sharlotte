<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `to_review` lets the project owner flag individual ingredients (found via manual
     * data-quality review — arithmetic sanity checks on nutrition macros, naming
     * inconsistencies, implausible values) to come back to later. Defaulted to `false`, so
     * unlike the `description_fr` migration, this is safe to run with a plain `migrate`
     * against the table's 1,937+ existing rows. Indexed since it is queried directly
     * (`WHERE to_review = true`) against that row count.
     *
     * No new timestamp column is added alongside it: the existing `updated_at` (from
     * ->timestamps()) is enough, since Laravel only bumps it when a row's data actually
     * changes — not on a no-op idempotent re-import.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('to_review')->default(false)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('to_review');
        });
    }
};
