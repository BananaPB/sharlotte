<?php

declare(strict_types=1);

use App\Models\Unit;
use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function addPreparationIdToUnitsMigration(): object
{
    return require database_path('migrations/2026_10_01_210003_add_preparation_id_to_units_table.php');
}

// recipe_lines references units and is created later, so it is rolled back
// first and re-applied last, exactly as `migrate:rollback` would.
function recipeLinesMigrationForUnitsRollback(): object
{
    return require database_path('migrations/2026_10_01_210004_create_recipe_lines_table.php');
}

test('can be rolled back, keeping ingredient units and dropping preparation units', function () {
    $ingredientUnit = UnitFactory::new()->create();
    UnitFactory::new()->forPreparation()->create();
    $recipeLines = recipeLinesMigrationForUnitsRollback();
    $migration = addPreparationIdToUnitsMigration();

    $recipeLines->down();
    $migration->down();

    expect(Schema::hasColumn('units', 'preparation_id'))->toBeFalse()
        ->and(collect(Schema::getColumns('units'))->firstWhere('name', 'ingredient_id')['nullable'])->toBeFalse()
        ->and(Unit::query()->toBase()->pluck('id')->all())->toBe([$ingredientUnit->id]);
});

test('can be re-applied after a rollback, restoring the one-component check', function () {
    $recipeLines = recipeLinesMigrationForUnitsRollback();
    $migration = addPreparationIdToUnitsMigration();

    $recipeLines->down();
    $migration->down();
    $migration->up();
    $recipeLines->up();

    expect(Schema::hasColumn('units', 'preparation_id'))->toBeTrue()
        ->and(collect(Schema::getColumns('units'))->firstWhere('name', 'ingredient_id')['nullable'])->toBeTrue();

    // Only the exception is asserted: Postgres aborts the transaction after the CHECK error.
    expect(fn () => UnitFactory::new()->create(['ingredient_id' => null]))
        ->toThrow(QueryException::class, 'units_one_component');
});
