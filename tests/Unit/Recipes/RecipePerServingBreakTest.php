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

/** @param  array<string, mixed>  $values */
function qaPsIngredient(array $values = []): Ingredient
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->create($values + [
        'calories' => '10', 'fats' => '10', 'saturates' => '10', 'carbohydrates' => '10', 'sugars' => '10',
        'fibers' => '10', 'proteins' => '10', 'salt' => '10', 'water' => '10',
    ]);

    return $ingredient;
}

function qaPsProduct(int $servings, ?User $owner = null): Product
{
    /** @var Product $p */
    $p = ProductFactory::new()->ownedBy($owner ?? User::factory()->create())->create(['servings' => $servings]);

    return $p;
}

function qaPsPrep(?User $owner = null): Preparation
{
    /** @var Preparation $p */
    $p = PreparationFactory::new()->ownedBy($owner ?? User::factory()->create())->create();

    return $p;
}

function qaPsLine(Product|Preparation $parent, Ingredient|Preparation $child, string $quantity): void
{
    $f = RecipeLineFactory::new();
    $f = $parent instanceof Product ? $f->forProduct($parent) : $f->forPreparation($parent);
    $f = $child instanceof Ingredient ? $f->withIngredient($child) : $f->withComponentPreparation($child);
    $f->create(['quantity' => $quantity]);
}

function qaPsCountQueries(Closure $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

test('one serving gives the totals and the whole weight', function () {
    $a = qaPsIngredient(['calories' => '33.33']);

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '123.4567'], 1);

    expect($facts->perServing)->toBe($facts->totals)
        ->and($facts->servingWeightGrams)->toBe('123.4567');
});

test('keeps per-serving keys in the same order as the totals', function () {
    $a = qaPsIngredient();

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '100.0000'], 2);

    expect(array_keys($facts->perServing))->toBe(array_keys($facts->totals));
});

test('handles the largest servings a database row can hold', function () {
    $a = qaPsIngredient();

    // 100 g / 65535 = 0.00152590... -> 0.0015 ; 10 / 65535 = 0.00015 -> 0.00
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '100.0000'], 65535);

    expect($facts->servingWeightGrams)->toBe('0.0015')
        ->and($facts->perServing['calories'] ?? null)->toBe('0.00');
});

test('does not overflow or divide badly with an absurd servings count', function () {
    $a = qaPsIngredient();

    $facts = (new RecipeNutrition)->fromGrams([$a->id => '100.0000'], PHP_INT_MAX);

    expect($facts->servingWeightGrams)->toBe('0.0000')
        ->and($facts->perServing['calories'] ?? null)->toBe('0.00')
        ->and($facts->servings)->toBe(PHP_INT_MAX);
});

test('rounds a per-serving value of exactly half a hundredth up', function () {
    $a = qaPsIngredient(['calories' => '1']);

    // 50 g -> 0.5 in total; 0.5 / 100 = 0.005 -> 0.01
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '50.0000'], 100);

    expect($facts->perServing['calories'] ?? null)->toBe('0.01');
});

test('rounds a per-serving value just under half a hundredth down', function () {
    $a = qaPsIngredient(['calories' => '1']);

    // 4.9999 g -> 0.049999 ; / 10 = 0.0049999 -> 0.00
    $facts = (new RecipeNutrition)->fromGrams([$a->id => '4.9999'], 10);

    expect($facts->perServing['calories'] ?? null)->toBe('0.00');
});

test('rounds the serving weight just under half a ten-thousandth down', function () {
    $a = qaPsIngredient();

    // 0.0001 g / 3 = 0.0000333 -> 0.0000 ; 0.0003 g / 4 = 0.000075 -> 0.0001
    expect((new RecipeNutrition)->fromGrams([$a->id => '0.0001'], 3)->servingWeightGrams)->toBe('0.0000')
        ->and((new RecipeNutrition)->fromGrams([$a->id => '0.0003'], 4)->servingWeightGrams)->toBe('0.0001');
});

test('leaves per100g and totals untouched when servings are given', function () {
    $a = qaPsIngredient(['calories' => '1', 'fibers' => null]);
    $grams = [$a->id => '14.5000'];

    $without = (new RecipeNutrition)->fromGrams($grams);
    $with = (new RecipeNutrition)->fromGrams($grams, 7);

    expect($with->totals)->toBe($without->totals)
        ->and($with->per100g)->toBe($without->per100g)
        ->and($with->weightGrams)->toBe($without->weightGrams);
});

test('refuses servings below one through compute() too, naming the value', function () {
    $product = qaPsProduct(1);

    expect(fn () => (new RecipeNutrition)->compute($product, -12))
        ->toThrow(InvalidArgumentException::class, '-12')
        ->and(fn () => (new RecipeNutrition)->fromGrams([], 0))
        ->toThrow(InvalidArgumentException::class, '0');
});

test('reports an ingredient used on several nested lines once, with all grams summed', function () {
    $a = qaPsIngredient(['calories' => '100']);
    $owner = User::factory()->create();
    $prep = qaPsPrep($owner);
    qaPsLine($prep, $a, '30.00');
    $product = qaPsProduct(3, $owner);
    qaPsLine($product, $a, '20.00');
    // The preparation weighs 30 g; a line of 60 g uses it twice over, a line of 30 g once.
    qaPsLine($product, $prep, '60.00');
    qaPsLine($product, $prep, '30.00');

    $report = (new RecipeReporter)->compute($product);

    // 20 + 60 + 30 = 110 g at 100 kcal per 100 g = 110.00 ; / 3 = 36.67
    expect($report->weightGrams)->toBe('110.0000')
        ->and($report->nutrition->totals['calories'])->toBe('110.00')
        ->and($report->nutrition->perServing['calories'] ?? null)->toBe('36.67')
        ->and($report->nutrition->servingWeightGrams)->toBe('36.6667');
});

test('keeps per-serving fibers unknown when a nested ingredient lacks them', function () {
    $known = qaPsIngredient();
    $unknown = qaPsIngredient(['fibers' => null]);
    $owner = User::factory()->create();
    $prep = qaPsPrep($owner);
    qaPsLine($prep, $unknown, '10.00');
    $product = qaPsProduct(2, $owner);
    qaPsLine($product, $known, '100.00');
    qaPsLine($product, $prep, '1.00');

    $report = (new RecipeReporter)->compute($product);

    expect($report->nutrition->perServing)->toHaveKey('fibers', null)
        ->and($report->nutrition->perServing['water'] ?? null)->not->toBeNull();
});

// A zero-quantity line cannot exist (database check), so an empty product stands for "zero weight".
test('reports an empty product as zeros and never divides by zero', function () {
    $report = (new RecipeReporter)->compute(qaPsProduct(5));

    expect($report->weightGrams)->toBe('0.0000')
        ->and($report->nutrition->perServing['calories'] ?? null)->toBe('0.00')
        ->and($report->nutrition->servingWeightGrams)->toBe('0.0000')
        ->and($report->nutrition->per100g['calories'])->toBeNull()
        ->and($report->servings)->toBe(5);
});

test('gives a product with one serving non-null per-serving values', function () {
    $product = qaPsProduct(1);
    qaPsLine($product, qaPsIngredient(), '50.00');

    $report = (new RecipeReporter)->compute($product);

    expect($report->servings)->toBe(1)
        ->and($report->nutrition->perServing)->toBe($report->nutrition->totals);
});

test('does not leak state between two reports made by the same reporter', function () {
    $reporter = new RecipeReporter;
    $a = qaPsIngredient(['calories' => '100']);
    $first = qaPsProduct(2);
    qaPsLine($first, $a, '100.00');
    $second = qaPsPrep();
    qaPsLine($second, $a, '10.00');

    $r1 = $reporter->compute($first);
    $r2 = $reporter->compute($second);
    $r1Again = $reporter->compute($first);

    expect($r2->weightGrams)->toBe('10.0000')
        ->and($r2->servings)->toBeNull()
        ->and($r2->nutrition->perServing)->toBeNull()
        ->and($r1Again)->toEqual($r1);
});

test('reports allergens of an ingredient hidden two preparations deep in a product', function () {
    $a = qaPsIngredient();
    $allergen = AllergenFactory::new()->create();
    $a->allergens()->attach($allergen->id);
    $owner = User::factory()->create();
    $inner = qaPsPrep($owner);
    qaPsLine($inner, $a, '5.00');
    $outer = qaPsPrep($owner);
    qaPsLine($outer, $inner, '1.00');
    $product = qaPsProduct(2, $owner);
    qaPsLine($product, $outer, '1.00');

    $report = (new RecipeReporter)->compute($product);

    expect($report->allergens)->toEqual((new RecipeAllergens)->compute($product))
        ->and($report->allergens->allergens)->not->toBeEmpty();
});

test('cannot be modified once built', function () {
    $report = (new RecipeReporter)->compute(qaPsProduct(2));

    expect(fn () => $report->servings = 9)->toThrow(Error::class)
        ->and($report)->toBeInstanceOf(RecipeReport::class);
});

test('report query count does not depend on servings or on how many ingredients are used', function () {
    $small = qaPsProduct(2);
    qaPsLine($small, qaPsIngredient(), '1.00');
    $big = qaPsProduct(900);
    foreach (range(1, 15) as $i) {
        qaPsLine($big, qaPsIngredient(), '1.00');
    }

    expect(qaPsCountQueries(fn () => (new RecipeReporter)->compute($big)))
        ->toBe(qaPsCountQueries(fn () => (new RecipeReporter)->compute($small)));
});
