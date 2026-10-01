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
     * Units now apply to preparations too ("1 part de crème pâtissière = 80 g"): a unit's
     * component is either an ingredient or a preparation, exactly one of them
     * (docs/decisions.md entry 8).
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // Relaxing NOT NULL is non-destructive: every existing row keeps its ingredient.
            // The foreign key itself is untouched by ->change().
            $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            // cascadeOnDelete: like ingredient units, a unit means nothing without its component.
            $table->foreignId('preparation_id')->nullable()
                ->constrained('preparations')->cascadeOnDelete();
            // Postgres does not auto-index foreign key columns.
            $table->index('preparation_id');
        });

        // Static statement, no user input (CLAUDE.md §3.2): the schema builder cannot express
        // CHECK constraints. num_nonnulls() counts its non-null arguments.
        DB::statement('ALTER TABLE units ADD CONSTRAINT units_one_component CHECK (num_nonnulls(ingredient_id, preparation_id) = 1)');
    }

    /**
     * Reverse the migrations.
     *
     * Preparation units are deleted rather than refused: once preparation_id is dropped they
     * would have no component and could not satisfy ingredient_id NOT NULL, and the
     * preparations table they belong to is dropped by an earlier migration's down() right
     * after this one anyway. Refusing would only block the rollback until someone deleted
     * the very same rows by hand.
     */
    public function down(): void
    {
        // Static statement, no user input (CLAUDE.md §3.2).
        DB::statement('ALTER TABLE units DROP CONSTRAINT units_one_component');

        DB::table('units')->whereNotNull('preparation_id')->delete();

        Schema::table('units', function (Blueprint $table) {
            $table->dropForeign(['preparation_id']);
            $table->dropIndex(['preparation_id']);
            $table->dropColumn('preparation_id');
            $table->unsignedBigInteger('ingredient_id')->nullable(false)->change();
        });
    }
};
