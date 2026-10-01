<?php

declare(strict_types=1);

use Database\Factories\RecipeLineFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function createRecipeLinesTableMigration(): object
{
    return require database_path('migrations/2026_10_01_210004_create_recipe_lines_table.php');
}

test('can be rolled back and re-applied, restoring the positive-quantity check', function () {
    RecipeLineFactory::new()->create();
    $migration = createRecipeLinesTableMigration();

    $migration->down();
    expect(Schema::hasTable('recipe_lines'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('recipe_lines'))->toBeTrue();

    // Only the exception is asserted: Postgres aborts the transaction after the CHECK error.
    expect(fn () => RecipeLineFactory::new()->create(['quantity' => 0]))
        ->toThrow(QueryException::class, 'recipe_lines_quantity_positive');
});

test('restores the one-parent check when re-applied', function () {
    $migration = createRecipeLinesTableMigration();

    $migration->down();
    $migration->up();

    expect(fn () => RecipeLineFactory::new()->create(['product_id' => null]))
        ->toThrow(QueryException::class, 'recipe_lines_one_parent');
});
