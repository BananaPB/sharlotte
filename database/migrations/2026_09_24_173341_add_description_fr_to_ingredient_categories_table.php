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
     * Adds `description_fr`, a display-only column alongside `label_fr` (same reasoning as
     * that column: `code` stays the stable identity, both `label_fr` and `description_fr`
     * are translatable later without touching it — see docs/decisions.md, deferred 2026-09-22).
     * Not nullable, matching `label_fr`'s pattern: every category gets one via the seeder.
     *
     * WARNING: run via `migrate:fresh`, not a plain `migrate`, on any database already seeded
     * with the prior 7-category version — a non-nullable column with no default will fail to
     * add against existing rows.
     */
    public function up(): void
    {
        Schema::table('ingredient_categories', function (Blueprint $table) {
            // Non-nullable, no default: requires `migrate:fresh` against a pre-existing,
            // already-seeded `ingredient_categories` table (see docblock above).
            $table->string('description_fr');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredient_categories', function (Blueprint $table) {
            $table->dropColumn('description_fr');
        });
    }
};
