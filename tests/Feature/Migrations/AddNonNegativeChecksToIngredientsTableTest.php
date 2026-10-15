<?php

declare(strict_types=1);

use App\Models\Ingredient;
use Database\Factories\IngredientFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const NON_NEGATIVE_COLUMNS = ['calories', 'fats', 'saturates', 'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water'];
const NULLABLE_NON_NEGATIVE_COLUMNS = ['fibers', 'water'];

function addNonNegativeChecksMigration(): object
{
    return require database_path('migrations/2026_10_15_090001_add_non_negative_checks_to_ingredients_table.php');
}

// Returns true when the database accepts the value. The nested transaction is a savepoint:
// Postgres aborts the whole transaction on a failed statement, which would otherwise break
// the rest of the test running inside RefreshDatabase's transaction.
function databaseAccepts(string $column, float|int|null $value): bool
{
    try {
        DB::transaction(fn () => IngredientFactory::new()->create([$column => $value]));

        return true;
    } catch (QueryException) {
        return false;
    }
}

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
test('refuses a negative value, and accepts zero and a positive value, on every nutrition column', function (string $column) {
    expect(databaseAccepts($column, -0.01))->toBeFalse();
    expect(databaseAccepts($column, 0))->toBeTrue();
    expect(databaseAccepts($column, 12.34))->toBeTrue();
})->with(NON_NEGATIVE_COLUMNS);

test('still accepts an unknown (null) value on the nullable nutrition columns', function (string $column) {
    expect(databaseAccepts($column, null))->toBeTrue();
})->with(NULLABLE_NON_NEGATIVE_COLUMNS);

test('refuses a negative value on update too, not only on insert', function () {
    $ingredient = IngredientFactory::new()->create();

    expect(fn () => DB::transaction(
        fn () => Ingredient::query()->whereKey($ingredient->id)->update(['sugars' => -1])
    ))->toThrow(QueryException::class);
});

test('can be rolled back and re-applied', function () {
    $migration = addNonNegativeChecksMigration();

    $migration->down();

    foreach (NON_NEGATIVE_COLUMNS as $column) {
        expect(databaseAccepts($column, -0.01))->toBeTrue();
    }

    // Remove the negatives the rolled-back state allowed, so re-applying can succeed.
    Ingredient::query()->delete();

    $migration->up();

    foreach (NON_NEGATIVE_COLUMNS as $column) {
        expect(databaseAccepts($column, -0.01))->toBeFalse();
    }
});

test('applies on a set of ordinary ingredients without changing any of them', function () {
    $migration = addNonNegativeChecksMigration();
    $migration->down();

    IngredientFactory::new()->count(25)->create();
    IngredientFactory::new()->create(['fibers' => null, 'water' => null, 'fats' => 0]);
    $before = Ingredient::query()->toBase()->orderBy('id')->get()->all();

    $migration->up();

    expect(Ingredient::query()->toBase()->orderBy('id')->get()->all())->toEqual($before);
});

// QA additions: constraint names, rollback completeness, fail-safe on invalid existing data,
// and the absence of upper bounds.
function ingredientCheckNames(): array
{
    return collect(DB::select(
        "select conname from pg_constraint where conrelid = 'ingredients'::regclass and contype = 'c' and conname like 'ingredients\\_%\\_not\\_negative' order by conname"
    ))->pluck('conname')->all();
}

test('names each constraint ingredients_<column>_not_negative', function () {
    $expected = collect(NON_NEGATIVE_COLUMNS)->map(fn ($c) => 'ingredients_'.$c.'_not_negative')->sort()->values()->all();

    expect(ingredientCheckNames())->toBe($expected);
});

test('rolling back drops all nine constraints', function () {
    addNonNegativeChecksMigration()->down();

    expect(ingredientCheckNames())->toBe([]);
});

test('refuses to apply, and fixes nothing, when an existing ingredient is already negative', function () {
    $migration = addNonNegativeChecksMigration();
    $migration->down();
    $ingredient = IngredientFactory::new()->create(['salt' => -5]);

    expect(fn () => DB::transaction(fn () => $migration->up()))->toThrow(QueryException::class);

    expect((float) Ingredient::query()->whereKey($ingredient->id)->value('salt'))->toBe(-5.0);
});

// 999.99 is the largest value the decimal(5,2) columns can hold (calories is decimal(6,2)).
test('adds no upper bound: the largest storable value is still accepted', function (string $column) {
    expect(databaseAccepts($column, 999.99))->toBeTrue();
})->with(NON_NEGATIVE_COLUMNS);
