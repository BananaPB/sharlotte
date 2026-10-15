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
     * How many portions the product's recipe makes. Display-only: it never changes
     * composition or how a product is scaled, and it is never a component quantity.
     * Products only: preparations deliberately have no servings. Existing rows get 1.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('servings')->default(1);
        });

        // Static statement, no user input (CLAUDE.md §3.2): the schema builder cannot express
        // CHECK constraints.
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_servings_positive CHECK (servings >= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Static statement, no user input (CLAUDE.md §3.2).
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_servings_positive');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('servings');
        });
    }
};
