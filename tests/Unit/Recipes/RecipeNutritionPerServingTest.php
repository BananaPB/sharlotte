<?php

declare(strict_types=1);

use App\Models\Ingredient;
use App\Recipes\NutritionFacts;
use App\Recipes\RecipeNutrition;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\RecipeLineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $values  nutrition values per 100 g, overriding the all-"10" default
 */
function perServingIngredient(array $values = []): Ingredient
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->create($values + [
        'calories' => '10', 'fats' => '10', 'saturates' => '10', 'carbohydrates' => '10', 'sugars' => '10',
        'fibers' => '10', 'proteins' => '10', 'salt' => '10', 'water' => '10',
    ]);

    return $ingredient;
}

test('gives no per-serving values when no servings count is given', function () {
    $a = perServingIngredient();

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '100.0000']);

    expect($facts->servings)->toBeNull()
        ->and($facts->perServing)->toBeNull()
        ->and($facts->servingWeightGrams)->toBeNull();
});

test('keeps the old three-argument constructor working', function () {
    $facts = new NutritionFacts('0.0000', [], []);

    expect($facts->servings)->toBeNull()
        ->and($facts->perServing)->toBeNull()
        ->and($facts->servingWeightGrams)->toBeNull();
});

test('divides the totals by the servings', function () {
    $a = perServingIngredient();

    // 200 g at 10 per 100 g = 20.00 per nutrient in total; 20 / 4 = 5.00.
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '200.0000'], 4);

    expect($facts->servings)->toBe(4)
        ->and($facts->perServing)->toBe([
            'calories' => '5.00', 'fats' => '5.00', 'saturates' => '5.00', 'carbohydrates' => '5.00',
            'sugars' => '5.00', 'fibers' => '5.00', 'proteins' => '5.00', 'salt' => '5.00', 'water' => '5.00',
        ]);
});

test('computes the weight of one serving', function () {
    $a = perServingIngredient();

    // 200 g / 3 = 66.66666... -> 66.6667 (half-up at 4 decimals).
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '200.0000'], 3);

    expect($facts->servingWeightGrams)->toBe('66.6667');
});

test('rounds the serving weight half up', function () {
    $a = perServingIngredient();

    // 0.0001 g / 2 = 0.00005 -> 0.0001 (half-up).
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '0.0001'], 2);

    expect($facts->servingWeightGrams)->toBe('0.0001');
});

test('rounds per-serving values from the unrounded total, not from the rounded one', function () {
    $a = perServingIngredient(['calories' => '1']);

    // Unrounded total: 14.5 g x 1 / 100 = 0.145 (shown as 0.15).
    // 0.145 / 2 = 0.0725 -> 0.07. Dividing the rounded 0.15 would give 0.075 -> 0.08.
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '14.5000'], 2);

    expect($facts->totals['calories'])->toBe('0.15')
        ->and($facts->perServing['calories'] ?? null)->toBe('0.07');
});

test('rounds per-serving values half up', function () {
    $a = perServingIngredient(['calories' => '1']);

    // 0.15 / 2 = 0.075 -> 0.08.
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '15.0000'], 2);

    expect($facts->perServing['calories'] ?? null)->toBe('0.08');
});

test('leaves per-serving fibers and water unknown when an ingredient lacks them', function () {
    $a = perServingIngredient();
    $b = perServingIngredient(['fibers' => null, 'water' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '100.0000', $b->id => '100.0000'], 2);

    expect($facts->perServing)->toHaveKey('fibers', null)
        ->and($facts->perServing)->toHaveKey('water', null)
        // 2 x 10 = 20 in total, / 2 servings.
        ->and($facts->perServing['calories'] ?? null)->toBe('10.00');
});

test('returns zeros per serving for an empty recipe', function () {
    $facts = (new RecipeNutrition)->fromGrams([], 4);

    expect($facts->perServing)->toBe([
        'calories' => '0.00', 'fats' => '0.00', 'saturates' => '0.00', 'carbohydrates' => '0.00',
        'sugars' => '0.00', 'fibers' => '0.00', 'proteins' => '0.00', 'salt' => '0.00', 'water' => '0.00',
    ])
        ->and($facts->servingWeightGrams)->toBe('0.0000')
        ->and($facts->servings)->toBe(4)
        ->and($facts->per100g['calories'])->toBeNull();
});

test('returns zeros per serving for a recipe that weighs nothing', function () {
    $a = perServingIngredient(['fibers' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '0.0000'], 3);

    expect($facts->perServing['calories'] ?? null)->toBe('0.00')
        ->and($facts->perServing)->toHaveKey('fibers', null)
        ->and($facts->servingWeightGrams)->toBe('0.0000')
        ->and($facts->per100g['calories'])->toBeNull();
});

test('refuses fewer than one serving, naming the value', function (int $servings) {
    $a = perServingIngredient();

    expect(fn () => (new RecipeNutrition)->fromGrams([$a->id => '100.0000'], $servings))
        ->toThrow(InvalidArgumentException::class, (string) $servings);
})->with([0, -3]);

test('refuses invalid servings before running any query', function (int $servings) {
    // A recipe that would otherwise be flattened (queries), so a late check would show up.
    $preparation = PreparationFactory::new()->create();
    RecipeLineFactory::new()->forPreparation($preparation)->withIngredient(perServingIngredient())->create(['quantity' => '100']);

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(fn () => (new RecipeNutrition)->compute($preparation, $servings))
            ->toThrow(InvalidArgumentException::class, (string) $servings);
        $queries = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($queries)->toBe(0);
})->with([0, -1]);
