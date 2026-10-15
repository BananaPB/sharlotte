<?php

declare(strict_types=1);

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\User;
use App\Recipes\RecipeAllergens;
use App\Recipes\RecipeNutrition;
use App\Recipes\RecipeReport;
use App\Recipes\RecipeReporter;
use Database\Factories\AllergenFactory;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function reporterIngredient(): Ingredient
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->create([
        'calories' => '120', 'fats' => '3', 'saturates' => '1', 'carbohydrates' => '20', 'sugars' => '5',
        'fibers' => '2', 'proteins' => '7', 'salt' => '0.5', 'water' => '60',
    ]);

    return $ingredient;
}

function reporterProduct(User $owner, int $servings = 1): Product
{
    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy($owner)->create(['servings' => $servings]);

    return $product;
}

function reporterPreparation(User $owner): Preparation
{
    /** @var Preparation $preparation */
    $preparation = PreparationFactory::new()->ownedBy($owner)->create();

    return $preparation;
}

function reporterAddIngredient(Product|Preparation $parent, Ingredient $ingredient, string $quantity): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory->withIngredient($ingredient)->create(['quantity' => $quantity]);
}

function reporterAddPreparation(Product|Preparation $parent, Preparation $component, string $quantity): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory->withComponentPreparation($component)->create(['quantity' => $quantity]);
}

/** A product with a nested preparation, one allergen on the ingredient. */
function reporterNestedProduct(int $servings = 4): Product
{
    $owner = User::factory()->create();
    $ingredient = reporterIngredient();
    $allergen = AllergenFactory::new()->create();
    $ingredient->allergens()->attach($allergen->id);

    $prep = reporterPreparation($owner);
    reporterAddIngredient($prep, $ingredient, '150.00');
    $product = reporterProduct($owner, $servings);
    reporterAddIngredient($product, $ingredient, '33.00');
    reporterAddPreparation($product, $prep, '75.00');

    return $product;
}

test('reports the same nutrition and allergens as the separate computations for a nested product', function () {
    $product = reporterNestedProduct(4);

    $report = (new RecipeReporter)->compute($product);

    expect($report)->toBeInstanceOf(RecipeReport::class)
        ->and($report->nutrition)->toEqual((new RecipeNutrition)->compute($product, 4))
        ->and($report->allergens)->toEqual((new RecipeAllergens)->compute($product))
        ->and($report->weightGrams)->toBe($report->nutrition->weightGrams)
        ->and($report->servings)->toBe(4)
        ->and($report->allergens->allergens)->not->toBeEmpty();
});

test('reports the same as the separate computations for a preparation root', function () {
    $owner = User::factory()->create();
    $prep = reporterPreparation($owner);
    reporterAddIngredient($prep, reporterIngredient(), '80.00');

    $report = (new RecipeReporter)->compute($prep);

    expect($report->nutrition)->toEqual((new RecipeNutrition)->compute($prep))
        ->and($report->allergens)->toEqual((new RecipeAllergens)->compute($prep));
});

test('reports an empty recipe', function () {
    $product = reporterProduct(User::factory()->create(), 2);

    $report = (new RecipeReporter)->compute($product);

    expect($report->weightGrams)->toBe('0.0000')
        ->and($report->nutrition)->toEqual((new RecipeNutrition)->compute($product, 2))
        ->and($report->allergens)->toEqual((new RecipeAllergens)->compute($product))
        ->and($report->nutrition->servingWeightGrams)->toBe('0.0000');
});

test('gives a product its servings and per-serving values but a preparation none', function () {
    $owner = User::factory()->create();
    $ingredient = reporterIngredient();

    $product = reporterProduct($owner, 4);
    reporterAddIngredient($product, $ingredient, '200.00');
    $prep = reporterPreparation($owner);
    reporterAddIngredient($prep, $ingredient, '200.00');

    $productReport = (new RecipeReporter)->compute($product);
    $prepReport = (new RecipeReporter)->compute($prep);

    expect($productReport->servings)->toBe(4)
        ->and($productReport->nutrition->perServing)->not->toBeNull()
        // 200 g at 120 kcal per 100 g = 240.00; 240 / 4 = 60.00.
        ->and($productReport->nutrition->perServing['calories'] ?? null)->toBe('60.00')
        ->and($productReport->nutrition->servingWeightGrams)->toBe('50.0000')
        ->and($prepReport->servings)->toBeNull()
        ->and($prepReport->nutrition->perServing)->toBeNull()
        ->and($prepReport->nutrition->servingWeightGrams)->toBeNull();
});

/** @return int number of queries run by the callback */
function reporterCountQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Same depth for any width: a product with $width preparations, each holding one ingredient and one nested preparation. */
function reporterTree(int $width): Product
{
    $owner = User::factory()->create();
    $product = reporterProduct($owner, 3);

    foreach (range(1, $width) as $ignored) {
        $middle = reporterPreparation($owner);
        $leaf = reporterPreparation($owner);
        reporterAddIngredient($leaf, reporterIngredient(), '10.00');
        reporterAddPreparation($middle, $leaf, '5.00');
        reporterAddIngredient($middle, reporterIngredient(), '20.00');
        reporterAddPreparation($product, $middle, '10.00');
    }

    return $product;
}

test('runs fewer queries than the two separate computations', function () {
    $product = reporterTree(2);

    $separate = reporterCountQueries(function () use ($product): void {
        (new RecipeNutrition)->compute($product, 3);
        (new RecipeAllergens)->compute($product);
    });
    $combined = reporterCountQueries(fn () => (new RecipeReporter)->compute($product));

    expect($combined)->toBeLessThan($separate);
});

test('runs the same number of queries for a wide tree as for a narrow one', function () {
    $narrow = reporterTree(1);
    $wide = reporterTree(4);

    expect(reporterCountQueries(fn () => (new RecipeReporter)->compute($wide)))
        ->toBe(reporterCountQueries(fn () => (new RecipeReporter)->compute($narrow)));
});
