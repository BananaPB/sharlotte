<?php

declare(strict_types=1);

use Database\Factories\PreparationFactory;
use Database\Factories\RecipeLineFactory;
use Database\Factories\UnitFactory;
use Illuminate\Support\Facades\Schema;

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function createPreparationsTableMigration(): object
{
    return require database_path('migrations/2026_10_01_210001_create_preparations_table.php');
}

/**
 * Later migrations that depend on `preparations` (units.preparation_id, recipe_lines), in
 * apply order. They are rolled back first and re-applied afterwards, as `migrate:rollback`
 * would.
 *
 * @return list<object>
 */
function migrationsDependingOnPreparations(): array
{
    return [
        require database_path('migrations/2026_10_01_210003_add_preparation_id_to_units_table.php'),
        require database_path('migrations/2026_10_01_210004_create_recipe_lines_table.php'),
    ];
}

test('can be rolled back and re-applied, along with what depends on it', function () {
    $migration = createPreparationsTableMigration();
    $dependents = migrationsDependingOnPreparations();

    foreach (array_reverse($dependents) as $dependent) {
        $dependent->down();
    }

    $migration->down();
    expect(Schema::hasTable('preparations'))->toBeFalse();

    $migration->up();

    foreach ($dependents as $dependent) {
        $dependent->up();
    }

    expect(Schema::hasTable('preparations'))->toBeTrue();

    // The re-created table is wired back into units and recipe lines.
    $preparation = PreparationFactory::new()->create();
    $unit = UnitFactory::new()->forPreparation($preparation)->create();
    $parent = PreparationFactory::new()->ownedBy($preparation->owner)->create();
    $line = RecipeLineFactory::new()->forPreparation($parent)->withUnit($unit)->create();

    expect($line->component_preparation_id)->toBe($preparation->id);
});
