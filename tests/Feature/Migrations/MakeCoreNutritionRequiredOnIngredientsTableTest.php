<?php

declare(strict_types=1);

use App\Models\Ingredient;
use Database\Factories\IngredientFactory;
use Illuminate\Support\Facades\Schema;

/**
 * @return array<string, bool> column name => nullable
 */
function ingredientColumnNullability(): array
{
    return collect(Schema::getColumns('ingredients'))->pluck('nullable', 'name')->all();
}

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function makeCoreNutritionRequiredMigration(): object
{
    return require database_path('migrations/2026_10_01_161954_make_core_nutrition_required_on_ingredients_table.php');
}

test('can be rolled back and re-applied, keeping existing ingredients', function () {
    $ingredient = IngredientFactory::new()->create(['calories' => 72.8]);
    $migration = makeCoreNutritionRequiredMigration();

    $migration->down();

    // Rollback restores the old integer calories column (lossy by design: 72.8 rounds to 73)
    // and makes the core columns nullable again.
    expect(Schema::getColumnType('ingredients', 'calories'))->toBe('int2');
    expect((int) Ingredient::query()->toBase()->where('id', $ingredient->id)->value('calories'))->toBe(73);
    expect(ingredientColumnNullability())->toMatchArray([
        'calories' => true, 'fats' => true, 'saturates' => true, 'carbohydrates' => true,
        'sugars' => true, 'proteins' => true, 'salt' => true,
    ]);

    $migration->up();

    expect(Schema::getColumnType('ingredients', 'calories'))->toBe('numeric');
    expect(Ingredient::query()->findOrFail($ingredient->id)->calories)->toBe('73.00');
    expect(ingredientColumnNullability())->toMatchArray([
        'calories' => false, 'fats' => false, 'saturates' => false, 'carbohydrates' => false,
        'sugars' => false, 'proteins' => false, 'salt' => false, 'fibers' => true, 'water' => true,
    ]);
});
