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

test('can be rolled back and re-applied, restoring the positive-grams check', function () {
    $migration = createUnitsTableMigration();

    $migration->down();
    expect(Schema::hasTable('units'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('units'))->toBeTrue();

    // Only the exception is asserted: Postgres aborts the transaction after the CHECK error.
    expect(fn () => UnitFactory::new()->create(['grams' => 0]))
        ->toThrow(QueryException::class, 'units_grams_positive');
});
