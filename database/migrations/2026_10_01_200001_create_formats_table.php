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
     * Closed, read-only lookup list of packaging/shape words (slice, bottle, box…) that a
     * unit attaches a gram weight to (docs/decisions.md entry 8). `code` is the stable
     * English identity; the labels are display-only.
     */
    public function up(): void
    {
        Schema::create('formats', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            // Two explicit labels on purpose: display picks singular/plural by quantity
            // (French: plural from 2), and plurals are never computed in code.
            $table->string('label_fr');
            $table->string('label_fr_plural');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('formats');
    }
};
