<?php

declare(strict_types=1);

use App\Models\IngredientCategory;
use Database\Factories\IngredientCategoryFactory;
use Database\Factories\IngredientFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// See tests/Unit/Models/IngredientTest.php for why this local binding is needed: Pest.php
// only wires Laravel's TestCase + RefreshDatabase into the Feature directory.
uses(TestCase::class, RefreshDatabase::class);

test('has many ingredients', function () {
    $category = IngredientCategoryFactory::new()->create();
    $ingredient = IngredientFactory::new()->create(['category_id' => $category->id]);

    $reloaded = $category->fresh('ingredients');

    expect($reloaded->ingredients)->toHaveCount(1)
        ->and($reloaded->ingredients->first()->id)->toBe($ingredient->id);
});

test('enforces a unique code', function () {
    IngredientCategoryFactory::new()->create(['code' => 'fruits']);

    expect(fn () => IngredientCategoryFactory::new()->create(['code' => 'fruits']))
        ->toThrow(QueryException::class);
});

test('requires a description at the database level', function () {
    // create() only inserts the attributes given, so omitting `description_fr` here leaves
    // the column out of the INSERT entirely — the migration gives it no default, so this
    // must fail on the NOT NULL constraint rather than silently storing an empty string.
    //
    // Own transaction (a Postgres SAVEPOINT) for the same reason as the delete-blocked test
    // above: an expected constraint violation must not poison the outer test transaction.
    expect(fn () => DB::transaction(fn () => IngredientCategory::query()->create([
        'code' => 'no_description',
        'label_fr' => 'Sans description',
    ])))->toThrow(QueryException::class);
});

test('deleting a category is blocked while ingredients still reference it', function () {
    // `restrictOnDelete()` on ingredients.category_id (see the ingredients migration):
    // categories are a shared lookup table, so silently cascading here would delete
    // ingredient data as a side effect of tidying up an unrelated category.
    $category = IngredientCategoryFactory::new()->create();
    IngredientFactory::new()->create(['category_id' => $category->id]);

    // Wrapped in its own transaction (a Postgres SAVEPOINT, since RefreshDatabase already
    // has the outer test transaction open) so the expected constraint violation only rolls
    // back this statement, not the whole test transaction — otherwise Postgres would refuse
    // any further query in this test with "current transaction is aborted".
    expect(fn () => DB::transaction(fn () => $category->delete()))->toThrow(QueryException::class);

    expect(IngredientCategory::query()->find($category->id))->not->toBeNull();
});
