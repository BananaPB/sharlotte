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
     * Pivot for "this ingredient definitely contains this allergen". Kept as its own table
     * rather than a single pivot with a type column — see the sibling
     * ingredient_allergen_trace migration and the Dev agent's summary for why.
     */
    public function up(): void
    {
        Schema::create('ingredient_allergen', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['ingredient_id', 'allergen_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_allergen');
    }
};
