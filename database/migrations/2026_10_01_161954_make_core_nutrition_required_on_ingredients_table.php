<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The EU's seven mandatory nutrition values — everything except `fibers` and `water`,
     * which stay nullable (null = unknown data point).
     *
     * @var list<string>
     */
    private const CORE_GRAM_COLUMNS = ['fats', 'saturates', 'carbohydrates', 'sugars', 'proteins', 'salt'];

    /**
     * Run the migrations.
     *
     * Makes the core nutrition values NOT NULL and stores `calories` as an exact decimal
     * instead of a rounded integer (docs/decisions.md entry 9). Existing rows keep their
     * already-rounded calories: re-run `ingredients:import` afterwards to restore decimals.
     */
    public function up(): void
    {
        // Changing live columns (CLAUDE.md §3.8) is safe here: the dataset has zero nulls in
        // these columns (docs/decisions.md entry 9), so NOT NULL cannot fail on existing rows.
        Schema::table('ingredients', function (Blueprint $table) {
            // using() is a static cast expression (no user input), made explicit so Postgres
            // never has to guess the integer -> numeric conversion.
            $table->decimal('calories', 6, 2)->nullable(false)->using('"calories"::numeric(6,2)')->change();

            foreach (self::CORE_GRAM_COLUMNS as $column) {
                $table->decimal($column, 5, 2)->nullable(false)->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Lossy by design: converting back to integer rounds decimal calories (e.g. 72.8
            // -> 73), which is exactly the pre-migration behaviour — an accepted rollback cost.
            $table->unsignedSmallInteger('calories')->nullable()->using('round("calories")::smallint')->change();

            foreach (self::CORE_GRAM_COLUMNS as $column) {
                $table->decimal($column, 5, 2)->nullable()->change();
            }
        });
    }
};
