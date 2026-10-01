<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A unit gives one format a weight in grams for one specific component, e.g. "1 tranche
     * de jambon = 40 g" (docs/domain-model.md §Units). `owner_id` null means a public unit;
     * privacy is derived from it, never stored. No uniqueness across (ingredient, format,
     * owner): "1 tranche fine = 20 g" and "1 tranche épaisse = 60 g" can legitimately
     * coexist (docs/decisions.md entry 8 accepts near-duplicates).
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // Phase 2 step 2 will make this nullable and add `preparation_id`, with a CHECK
            // that exactly one of the two is set (docs/decisions.md entry 8).
            // cascadeOnDelete: a unit has no meaning without its component.
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            // restrictOnDelete: formats are a closed seeded list; removing one in use must fail.
            $table->foreignId('format_id')->constrained('formats')->restrictOnDelete();
            // Private units die with their owner, like private ingredients do.
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Postgres does not auto-index foreign key columns (see the ingredients table
            // migration for why ->index() can't be chained onto ->constrained()).
            $table->index('ingredient_id');
            $table->index('format_id');
            $table->index('owner_id');
            $table->decimal('grams', 8, 2);
            $table->timestamps();
        });

        // The schema builder cannot express CHECK constraints, so this goes through a static
        // statement (CLAUDE.md §3.2: no user input). A zero or negative weight would silently
        // zero out or invert every recipe line using the unit.
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_grams_positive CHECK (grams > 0)');
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the table also drops its CHECK constraint.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
