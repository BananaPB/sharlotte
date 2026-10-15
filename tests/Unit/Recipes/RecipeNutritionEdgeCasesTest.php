<?php

declare(strict_types=1);

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Recipes\RecipeNutrition;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const QA_KEYS = ['calories', 'fats', 'saturates', 'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water'];

/** @param  array<string, mixed>  $values */
function qaIngredient(array $values = [], bool $private = false): Ingredient
{
    $factory = $private ? IngredientFactory::new()->private() : IngredientFactory::new();

    /** @var Ingredient $ingredient */
    $ingredient = $factory->create($values + [
        'calories' => '10', 'fats' => '10', 'saturates' => '10', 'carbohydrates' => '10', 'sugars' => '10',
        'fibers' => '10', 'proteins' => '10', 'salt' => '10', 'water' => '10',
    ]);

    return $ingredient;
}

test('keeps fibers unknown when one null leaf hides among many known ones', function () {
    $grams = [];
    for ($i = 0; $i < 9; $i++) {
        $grams[qaIngredient()->id] = '100.0000';
    }
    $grams[qaIngredient(['fibers' => null, 'water' => null])->id] = '100.0000';

    $facts = (new RecipeNutrition)->fromGrams($grams);

    expect($facts->totals['fibers'])->toBeNull()
        ->and($facts->totals['water'])->toBeNull()
        ->and($facts->per100g['fibers'])->toBeNull()
        ->and($facts->per100g['water'])->toBeNull()
        // 1000 g x 10 / 100
        ->and($facts->totals['calories'])->toBe('100.00')
        ->and($facts->per100g['calories'])->toBe('10.00');
});

test('keeps water unknown when the null leaf has a tiny quantity next to heavy known leaves', function () {
    $known = qaIngredient();
    $unknown = qaIngredient(['water' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$known->id => '5000.0000', $unknown->id => '0.0001']);

    expect($facts->totals['water'])->toBeNull()
        ->and($facts->per100g['water'])->toBeNull()
        ->and($facts->totals['fibers'])->toBe('500.00');
});

test('keeps fibers unknown when the null leaf weighs exactly zero beside known leaves', function () {
    $known = qaIngredient();
    $unknown = qaIngredient(['fibers' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$known->id => '100.0000', $unknown->id => '0.0000']);

    expect($facts->totals['fibers'])->toBeNull()
        ->and($facts->per100g['fibers'])->toBeNull()
        ->and($facts->totals['water'])->toBe('10.00')
        ->and($facts->per100g['water'])->toBe('10.00');
});

test('zero weight with several ingredients and a null water keeps water unknown and the rest at zero', function () {
    $a = qaIngredient();
    $b = qaIngredient(['water' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '0.0000', $b->id => '0.0000']);

    expect($facts->weightGrams)->toBe('0.0000')
        ->and($facts->totals['water'])->toBeNull()
        ->and($facts->totals['fibers'])->toBe('0.00')
        ->and($facts->totals['calories'])->toBe('0.00')
        ->and($facts->per100g)->toBe(array_fill_keys(QA_KEYS, null));
});

test('tells an empty product from a product whose ingredients all weigh nothing', function () {
    $empty = ProductFactory::new()->create();

    $facts = (new RecipeNutrition)->compute($empty);

    expect($facts->weightGrams)->toBe('0.0000')
        ->and($facts->totals)->toBe(array_fill_keys(QA_KEYS, '0.00'))
        ->and($facts->per100g)->toBe(array_fill_keys(QA_KEYS, null));

    $noFibers = qaIngredient(['fibers' => null]);
    // Stored quantities must be positive and scaling preserves the weight, so zero weight only arises from fromGrams
    $facts = (new RecipeNutrition)->fromGrams([$noFibers->id => '0.0000']);

    expect($facts->totals['fibers'])->toBeNull()
        ->and($facts->totals['salt'])->toBe('0.00')
        ->and($facts->per100g)->toBe(array_fill_keys(QA_KEYS, null));
});

test('rounds half-up around the .005 boundary', function (string $caloriesPer100g, string $grams, string $expected) {
    $ingredient = qaIngredient(['calories' => $caloriesPer100g]);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => $grams]);

    expect($facts->totals['calories'])->toBe($expected);
})->with([
    'just under half' => ['0.49', '1.0000', '0.00'],   // 0.0049
    'exactly half' => ['0.50', '1.0000', '0.01'],      // 0.005
    'just over half' => ['0.51', '1.0000', '0.01'],    // 0.0051
    'half after units' => ['50.50', '2.0000', '1.01'], // 1.01 exact
    'half on a larger number' => ['100.50', '1.0000', '1.01'], // 1.005
    'half at 9.995' => ['99.95', '10.0000', '10.00'],  // 9.995
]);

test('rounds a per 100 g value ending exactly on a half up', function () {
    $ingredient = qaIngredient(['calories' => '0.50']);

    $other = qaIngredient(['calories' => '0.25']);
    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '50.0000', $other->id => '50.0000']);

    // total = 0.25 + 0.125 = 0.375, weight 100 g, per 100 g = 0.375 -> 0.38
    expect($facts->totals['calories'])->toBe('0.38')
        ->and($facts->per100g['calories'])->toBe('0.38');
});

test('derives per 100 g from unrounded totals where rounding the total first would differ', function () {
    $ingredient = qaIngredient(['calories' => '14.28']);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '7.0000']);

    // total = 7 x 14.28 / 100 = 0.9996 -> 1.00; per 100 g = 14.28 (rounding the total first would give 14.29)
    expect($facts->totals['calories'])->toBe('1.00')
        ->and($facts->per100g['calories'])->toBe('14.28');
});

test('handles the smallest quantity of 0.0001 g', function () {
    $ingredient = qaIngredient(['calories' => '900.00']);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '0.0001']);

    // total = 0.0001 x 900 / 100 = 0.0009 -> 0.00; per 100 g = 900.00
    expect($facts->weightGrams)->toBe('0.0001')
        ->and($facts->totals['calories'])->toBe('0.00')
        ->and($facts->per100g['calories'])->toBe('900.00');
});

test('handles a very large quantity of one million grams', function () {
    $ingredient = qaIngredient(['calories' => '900.00', 'salt' => '0.01']);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '1000000.0000']);

    // total = 1 000 000 x 900 / 100 = 9 000 000
    expect($facts->weightGrams)->toBe('1000000.0000')
        ->and($facts->totals['calories'])->toBe('9000000.00')
        ->and($facts->per100g['calories'])->toBe('900.00')
        ->and($facts->totals['salt'])->toBe('100.00')
        ->and($facts->per100g['salt'])->toBe('0.01');
});

test('names every missing ingredient id that it can, and at least the first one', function () {
    $ingredient = qaIngredient();

    expect(fn () => (new RecipeNutrition)->fromGrams([$ingredient->id => '1.0000', 424242 => '1.0000']))
        ->toThrow(RuntimeException::class, '424242');
});

test('selects only the id and the nine nutrition columns, in a single query', function () {
    $ingredient = qaIngredient();

    DB::flushQueryLog();
    DB::enableQueryLog();
    (new RecipeNutrition)->fromGrams([$ingredient->id => '10.0000']);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($log)->toHaveCount(1);
    $sql = $log[0]['query'];
    foreach (['name', 'user_id', 'description', 'created_at'] as $forbidden) {
        expect($sql)->not->toContain($forbidden);
    }
    foreach ([...QA_KEYS, 'id'] as $column) {
        expect($sql)->toContain($column);
    }
});

test('a wide tree and a narrow tree cost the same number of queries for the nutrition step', function () {
    $narrow = qaIngredient();
    $wideIds = [];
    for ($i = 0; $i < 25; $i++) {
        $wideIds[qaIngredient()->id] = '3.0000';
    }

    $measure = function (array $grams): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new RecipeNutrition)->fromGrams($grams);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($measure($wideIds))->toBe($measure([$narrow->id => '3.0000']));
});

test('computes a preparation used at one third scale', function () {
    $a = qaIngredient(['calories' => '100.00']);
    $b = qaIngredient(['calories' => '200.00']);
    $owner = UserFactory::new()->create();
    /** @var Preparation $prep */
    $prep = PreparationFactory::new()->ownedBy($owner)->create();
    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy($owner)->create();
    RecipeLineFactory::new()->forPreparation($prep)->withIngredient($a)->create(['quantity' => '100.00']);
    RecipeLineFactory::new()->forPreparation($prep)->withIngredient($b)->create(['quantity' => '200.00']); // 300 g
    RecipeLineFactory::new()->forProduct($product)->withComponentPreparation($prep)->create(['quantity' => '100.00']);

    $facts = (new RecipeNutrition)->compute($product);

    // 1/3 of the preparation: 33.3333 g a (33.3333 cal) + 66.6667 g b (133.3334 cal) = 166.6667 -> 166.67 over 100 g
    expect($facts->weightGrams)->toBe('100.0000')
        ->and($facts->totals['calories'])->toBe('166.67')
        ->and($facts->per100g['calories'])->toBe('166.67');
});

test('a private ingredient inside a product behaves like a public one end to end', function () {
    $public = qaIngredient(['calories' => '100.00']);
    $private = qaIngredient(['calories' => '100.00'], private: true);
    /** @var Product $product */
    $product = ProductFactory::new()->create(['owner_id' => $private->owner_id]);
    RecipeLineFactory::new()->forProduct($product)->withIngredient($public)->create(['quantity' => '100.00']);
    RecipeLineFactory::new()->forProduct($product)->withIngredient($private)->create(['quantity' => '100.00']);

    $facts = (new RecipeNutrition)->compute($product);

    expect($facts->totals['calories'])->toBe('200.00')
        ->and($facts->per100g['calories'])->toBe('100.00');
});

test('the same ingredient reached directly and through a preparation is summed once per gram', function () {
    $a = qaIngredient(['calories' => '100.00', 'fibers' => null]);
    $owner = UserFactory::new()->create();
    /** @var Preparation $prep */
    $prep = PreparationFactory::new()->ownedBy($owner)->create();
    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy($owner)->create();
    RecipeLineFactory::new()->forPreparation($prep)->withIngredient($a)->create(['quantity' => '100.00']);
    RecipeLineFactory::new()->forProduct($product)->withComponentPreparation($prep)->create(['quantity' => '100.00']);
    RecipeLineFactory::new()->forProduct($product)->withIngredient($a)->create(['quantity' => '50.00']);

    $facts = (new RecipeNutrition)->compute($product);

    // 150 g of a: 150 calories, 100 per 100 g; fibers unknown
    expect($facts->weightGrams)->toBe('150.0000')
        ->and($facts->totals['calories'])->toBe('150.00')
        ->and($facts->per100g['calories'])->toBe('100.00')
        ->and($facts->totals['fibers'])->toBeNull();
});
