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
     * Lookup table for the ~20 fixed ingredient categories from the source spreadsheet.
     * `code` is the stable identity Phase 2's calculation engine will compare/join on;
     * `label_fr` is the display string, kept separate so it can be translated later
     * without touching the identity (see docs/decisions.md, deferred 2026-09-22).
     */
    public function up(): void
    {
        Schema::create('ingredient_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label_fr');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_categories');
    }
};
