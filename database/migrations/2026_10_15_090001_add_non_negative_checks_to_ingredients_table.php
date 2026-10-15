<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every nutrition column. The two nullable ones (fibers, water) still accept NULL:
     * a CHECK passes when its expression is NULL, so no explicit NULL clause is needed.
     *
     * @var list<string>
     */
    private const COLUMNS = ['calories', 'fats', 'saturates', 'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water'];

    /**
     * Run the migrations.
     *
     * Adds a non-negative CHECK per nutrition column so the database refuses bad data from
     * any writer. Fails (rather than fixing anything) if an existing row is already negative.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            // Raw SQL is required (the schema builder has no CHECK support); identifiers come
            // from the static COLUMNS list above, never from user input.
            DB::statement('ALTER TABLE ingredients ADD CONSTRAINT ingredients_'.$column.'_not_negative CHECK ('.$column.' >= 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            DB::statement('ALTER TABLE ingredients DROP CONSTRAINT IF EXISTS ingredients_'.$column.'_not_negative');
        }
    }
};
