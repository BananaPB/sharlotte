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
     * Pivot for "this ingredient may contain traces of this allergen" — deliberately a
     * separate table from ingredient_allergen (same shape, different meaning) rather than
     * one pivot with a "contains vs traces" type column: two plain belongsToMany relations
     * are trivial to eager-load and can't accidentally mix the two meanings by forgetting a
     * wherePivot() filter. The cost is a second near-identical table; accepted as the
     * simpler, harder-to-misuse option per CLAUDE.md's readability-over-cleverness rule.
     */
    public function up(): void
    {
        Schema::create('ingredient_allergen_trace', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['ingredient_id', 'allergen_id']);
            // The composite PK above only efficiently serves lookups on its leading column
            // (ingredient_id, i.e. Ingredient::allergenTraces()). The reverse direction —
            // Allergen::ingredientTraces(), needed by Phase 2's allergen-union/dedup logic
            // across the composition tree — needs allergen_id indexed on its own.
            $table->index('allergen_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_allergen_trace');
    }
};
