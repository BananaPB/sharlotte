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
     * An intermediate composition with its own recipe, reusable as a component of other
     * recipes (docs/domain-model.md). Deliberately minimal: no yield column, a recipe weighs
     * the sum of its lines (docs/decisions.md entry 10).
     */
    public function up(): void
    {
        Schema::create('preparations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Always owned, no public tier (docs/decisions.md entry 10); a preparation has no
            // meaning once its owner is gone, so it goes with them.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Postgres does not auto-index foreign key columns.
            $table->index('owner_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preparations');
    }
};
