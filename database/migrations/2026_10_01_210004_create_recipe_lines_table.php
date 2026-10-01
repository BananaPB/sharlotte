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
     * One line of a recipe, shared by preparations and products (docs/decisions.md entry 8):
     * a parent (preparation XOR product), a component (ingredient XOR preparation), a
     * quantity and an optional unit. A null unit means the quantity is in grams; with a unit
     * it is a count of that unit (2 x "1 tranche = 40 g").
     *
     * There is deliberately no product component column: "a product is never a component" is
     * enforced by the schema itself.
     */
    public function up(): void
    {
        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->id();

            // Parent: deleting a recipe deletes its lines.
            $table->foreignId('parent_preparation_id')->nullable()
                ->constrained('preparations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->cascadeOnDelete();

            // Component: a component in use must not be deletable (deleting a pastry cream
            // used by a tart must fail, docs/decisions.md entry 8). NO ACTION behaves like
            // RESTRICT in practice here (verified locally), and unlike RESTRICT it could later be
            // made DEFERRABLE without changing the rule. It does not make the user-deletion
            // cascade work by itself: Postgres runs each cascaded delete as its own internal
            // statement and checks there, so a product line using the user's own preparation
            // would block it — hence User's deleting() hook, which removes the user's recipe
            // lines first.
            $table->foreignId('ingredient_id')->nullable()
                ->constrained('ingredients')->noActionOnDelete();
            $table->foreignId('component_preparation_id')->nullable()
                ->constrained('preparations')->noActionOnDelete();

            // Null = quantity in grams. NO ACTION for the same reason as the components: a unit
            // in use must not silently vanish from a recipe (the line's "2 slices" would turn
            // into "2 grams"). Units cascade from their owner and their component; see above for
            // how user deletion copes with that.
            $table->foreignId('unit_id')->nullable()
                ->constrained('units')->noActionOnDelete();

            // No uniqueness on position, to keep reordering simple; lines are ordered by
            // position, then id.
            $table->unsignedInteger('position');
            $table->decimal('quantity', 10, 2);
            $table->timestamps();

            // Postgres does not auto-index foreign key columns.
            $table->index('parent_preparation_id');
            $table->index('product_id');
            $table->index('ingredient_id');
            $table->index('component_preparation_id');
            $table->index('unit_id');
        });

        // Static statements, no user input (CLAUDE.md §3.2): the schema builder cannot express
        // CHECK constraints.
        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_one_parent CHECK (num_nonnulls(parent_preparation_id, product_id) = 1)');
        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_one_component CHECK (num_nonnulls(ingredient_id, component_preparation_id) = 1)');
        // Only the trivial direct self-reference; the full cycle guard is Phase 2 step 3. A
        // CHECK passes when either side is null, which is what product and ingredient lines need.
        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_no_self_reference CHECK (parent_preparation_id <> component_preparation_id)');
        // A zero or negative quantity would silently zero out or invert the recipe's totals.
        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_quantity_positive CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the table also drops its CHECK constraints.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
    }
};
