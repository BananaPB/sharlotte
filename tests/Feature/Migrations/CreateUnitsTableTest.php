<?php

declare(strict_types=1);

use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function createUnitsTableMigration(): object
{
    return require database_path('migrations/2026_10_01_200002_create_units_table.php');
}

/**
 * Later migrations that depend on `units`: recipe_lines references it, and
 * add_preparation_id_to_units alters it. They must be rolled back first (in reverse order)
 * and re-applied afterwards, exactly as `migrate:rollback` would do.
 *
 * @return list<object> in apply order
 */
function migrationsDependingOnUnits(): array
{
    return [
        require database_path('migrations/2026_10_01_210003_add_preparation_id_to_units_table.php'),
        require database_path('migrations/2026_10_01_210004_create_recipe_lines_table.php'),
    ];
}

test('can be rolled back and re-applied, restoring the positive-grams check', function () {
    $migration = createUnitsTableMigration();
    $dependents = migrationsDependingOnUnits();

    foreach (array_reverse($dependents) as $dependent) {
        $dependent->down();
    }

    $migration->down();
    expect(Schema::hasTable('units'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('units'))->toBeTrue();

    foreach ($dependents as $dependent) {
        $dependent->up();
    }

    expect(Schema::hasColumn('units', 'preparation_id'))->toBeTrue()
        ->and(Schema::hasTable('recipe_lines'))->toBeTrue();

    // Only the exception is asserted: Postgres aborts the transaction after the CHECK error.
    expect(fn () => UnitFactory::new()->create(['grams' => 0]))
        ->toThrow(QueryException::class, 'units_grams_positive');
});
