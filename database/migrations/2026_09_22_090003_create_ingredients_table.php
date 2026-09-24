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
     * `storage` is a stable code (fresh/frozen/dry), never the raw French word — it is
     * part of the ingredient's identity per docs/domain-model.md, not a mutable attribute.
     *
     * `privacy` mirrors `owner_id`'s nullability (null = public/admin-owned) and is kept in
     * sync automatically by App\Models\Ingredient — see that model for why both exist.
     *
     * Nutrition columns are nullable on purpose: for `water` especially, 0 (measured at
     * zero grams) and null (unknown data point) are different facts, and the source data
     * may have gaps in the other nutrition fields too.
     */
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('ingredient_categories')->restrictOnDelete();
            // Private ingredients are visible to their owner only and are never shared (see
            // docs/domain-model.md) — with no other user able to see or reference them, they
            // have no meaning once that owner is gone, so deleting the user cascades here.
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Unlike MySQL/InnoDB, Postgres does not auto-index a foreign key column — only
            // the referenced primary key is indexed automatically. Phase 2's queries filter
            // and join on both, so index them explicitly. Chaining ->index() directly onto
            // ->constrained()->...OnDelete() would silently no-op: constrained() returns a
            // separate ForeignKeyDefinition object, not the column, so the fluent modifier
            // would land on the wrong object.
            $table->index('category_id');
            $table->index('owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('storage', ['fresh', 'frozen', 'dry']);
            $table->enum('privacy', ['public', 'private'])->default('public');
            $table->unsignedSmallInteger('calories')->nullable();
            $table->decimal('fats', 5, 2)->nullable();
            $table->decimal('saturates', 5, 2)->nullable();
            $table->decimal('carbohydrates', 5, 2)->nullable();
            $table->decimal('sugars', 5, 2)->nullable();
            $table->decimal('fibers', 5, 2)->nullable();
            $table->decimal('proteins', 5, 2)->nullable();
            $table->decimal('salt', 5, 2)->nullable();
            $table->decimal('water', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
