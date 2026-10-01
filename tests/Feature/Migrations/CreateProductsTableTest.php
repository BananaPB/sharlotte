<?php

declare(strict_types=1);

use Database\Factories\RecipeLineFactory;
use Illuminate\Support\Facades\Schema;

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function createProductsTableMigration(): object
{
    return require database_path('migrations/2026_10_01_210002_create_products_table.php');
}

// recipe_lines references products and is created later, so it is rolled back first and
// re-applied last, as `migrate:rollback` would.
function recipeLinesMigrationForProductsRollback(): object
{
    return require database_path('migrations/2026_10_01_210004_create_recipe_lines_table.php');
}

test('can be rolled back and re-applied, along with recipe lines', function () {
    $migration = createProductsTableMigration();
    $recipeLines = recipeLinesMigrationForProductsRollback();

    $recipeLines->down();
    $migration->down();
    expect(Schema::hasTable('products'))->toBeFalse();

    $migration->up();
    $recipeLines->up();
    expect(Schema::hasTable('products'))->toBeTrue();

    // The re-created table is wired back into recipe lines.
    $line = RecipeLineFactory::new()->forProduct()->create();

    expect($line->product)->not->toBeNull();
});
