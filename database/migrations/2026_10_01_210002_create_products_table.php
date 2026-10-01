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
     * The finished item, top of the tree, never used as a component (docs/domain-model.md).
     * Same shape as `preparations` today, but a separate table on purpose: products may later
     * gain fields of their own (docs/decisions.md entry 8).
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Always owned, no public tier (docs/decisions.md entry 10).
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
        Schema::dropIfExists('products');
    }
};
