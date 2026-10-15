<?php

declare(strict_types=1);

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\User;
use App\Recipes\NutritionFacts;
use App\Recipes\RecipeNutrition;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $values  nutrition values per 100 g, overriding the all-"10" default
 */
function nutritionIngredient(array $values = []): Ingredient
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->create($values + [
        'calories' => '10', 'fats' => '10', 'saturates' => '10', 'carbohydrates' => '10', 'sugars' => '10',
        'fibers' => '10', 'proteins' => '10', 'salt' => '10', 'water' => '10',
    ]);

    return $ingredient;
}

/** @return array{0: Product, 1: Preparation, 2: User} a product and a preparation of the same owner */
function nutritionRecipes(): array
{
    $owner = User::factory()->create();

    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy($owner)->create();
    /** @var Preparation $preparation */
    $preparation = PreparationFactory::new()->ownedBy($owner)->create();

    return [$product, $preparation, $owner];
}

function nutritionAddIngredient(Product|Preparation $parent, Ingredient $ingredient, string $quantity): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory->withIngredient($ingredient)->create(['quantity' => $quantity]);
}

function nutritionAddPreparation(Product|Preparation $parent, Preparation $component, string $quantity): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory->withComponentPreparation($component)->create(['quantity' => $quantity]);
}

test('computes the nine values of a single ingredient, for the recipe and per 100 g', function () {
    $ingredient = nutritionIngredient([
        'calories' => '250.50', 'fats' => '12.00', 'saturates' => '3.00', 'carbohydrates' => '30.00',
        'sugars' => '5.00', 'fibers' => '2.50', 'proteins' => '8.00', 'salt' => '1.20', 'water' => '40.00',
    ]);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '200.0000']);

    expect($facts)->toBeInstanceOf(NutritionFacts::class)
        ->and($facts->weightGrams)->toBe('200.0000')
        // 200 g x value / 100 = 2 x value
        ->and($facts->totals)->toBe([
            'calories' => '501.00', 'fats' => '24.00', 'saturates' => '6.00', 'carbohydrates' => '60.00',
            'sugars' => '10.00', 'fibers' => '5.00', 'proteins' => '16.00', 'salt' => '2.40', 'water' => '80.00',
        ])
        // total / 200 g x 100 = total / 2 = the ingredient's own values
        ->and($facts->per100g)->toBe([
            'calories' => '250.50', 'fats' => '12.00', 'saturates' => '3.00', 'carbohydrates' => '30.00',
            'sugars' => '5.00', 'fibers' => '2.50', 'proteins' => '8.00', 'salt' => '1.20', 'water' => '40.00',
        ]);
});

test('adds up several ingredients and rounds half-up on the final result only', function () {
    $first = nutritionIngredient(['calories' => '100.00']);
    $second = nutritionIngredient(['calories' => '33.33']);

    $facts = (new RecipeNutrition)->fromGrams([$first->id => '50.0000', $second->id => '30.0000']);

    // total = 50 x 100/100 + 30 x 33.33/100 = 50 + 9.999 = 59.999 -> 60.00 (rounded once, at the end)
    // per 100 g = 59.999 / 80 g x 100 = 74.99875 -> 75.00
    expect($facts->weightGrams)->toBe('80.0000')
        ->and($facts->totals['calories'])->toBe('60.00')
        ->and($facts->per100g['calories'])->toBe('75.00')
        // other values: 80 g x 10 / 100 = 8.00, and 10.00 per 100 g
        ->and($facts->totals['fats'])->toBe('8.00')
        ->and($facts->per100g['fats'])->toBe('10.00');
});

test('rounds a value ending exactly on a half up', function () {
    $ingredient = nutritionIngredient(['calories' => '0.50']);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '1.0000']);

    // 1 g x 0.50 / 100 = 0.005 -> 0.01 (half-up)
    expect($facts->totals['calories'])->toBe('0.01');
});

test('computes per 100 g from the unrounded totals', function () {
    $ingredient = nutritionIngredient(['calories' => '10.00']);

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '0.0300']);

    // total = 0.03 x 10 / 100 = 0.003 -> shown as 0.00, but per 100 g = 0.003 / 0.03 x 100 = 10.00
    // (from the rounded total it would wrongly be 0.00)
    expect($facts->totals['calories'])->toBe('0.00')
        ->and($facts->per100g['calories'])->toBe('10.00');
});

test('reports fibers and water as unknown as soon as one ingredient lacks them', function () {
    $known = nutritionIngredient();
    $noFibers = nutritionIngredient(['fibers' => null]);
    $noWater = nutritionIngredient(['water' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$known->id => '100.0000', $noFibers->id => '100.0000']);

    expect($facts->totals['fibers'])->toBeNull()
        ->and($facts->per100g['fibers'])->toBeNull()
        // water is known on both: 200 g x 10 / 100
        ->and($facts->totals['water'])->toBe('20.00')
        ->and($facts->per100g['water'])->toBe('10.00');

    $facts = (new RecipeNutrition)->fromGrams([$known->id => '100.0000', $noWater->id => '100.0000']);

    expect($facts->totals['water'])->toBeNull()
        ->and($facts->per100g['water'])->toBeNull()
        ->and($facts->totals['fibers'])->toBe('20.00');
});

test('gives zero totals and unknown per 100 g for an empty recipe', function () {
    $keys = ['calories', 'fats', 'saturates', 'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water'];

    $facts = (new RecipeNutrition)->fromGrams([]);

    expect($facts->weightGrams)->toBe('0.0000')
        ->and($facts->totals)->toBe(array_fill_keys($keys, '0.00'))
        ->and($facts->per100g)->toBe(array_fill_keys($keys, null));
});

test('does not divide by zero when ingredients are used but weigh nothing', function () {
    $ingredient = nutritionIngredient();

    $facts = (new RecipeNutrition)->fromGrams([$ingredient->id => '0.0000']);

    expect($facts->weightGrams)->toBe('0.0000')
        ->and($facts->totals['calories'])->toBe('0.00')
        ->and($facts->totals['fibers'])->toBe('0.00')
        ->and($facts->totals['water'])->toBe('0.00')
        ->and(array_filter($facts->per100g, fn ($value) => $value !== null))->toBe([]);
});

test('keeps fibers unknown, not zero, when a zero-weight ingredient lacks them', function () {
    $noFibers = nutritionIngredient(['fibers' => null]);

    $facts = (new RecipeNutrition)->fromGrams([$noFibers->id => '0.0000']);

    expect($facts->totals['fibers'])->toBeNull()
        ->and($facts->totals['water'])->toBe('0.00')
        ->and($facts->totals['salt'])->toBe('0.00')
        ->and(array_filter($facts->per100g, fn ($value) => $value !== null))->toBe([]);
});

test('names the id of an ingredient that does not exist', function () {
    $ingredient = nutritionIngredient();

    expect(fn () => (new RecipeNutrition)->fromGrams([$ingredient->id => '10.0000', 987654 => '5.0000']))
        ->toThrow(RuntimeException::class, '987654');
});

test('loads the ingredients with the same number of queries whatever the width of the recipe', function () {
    $count = function (int $ingredients): int {
        $grams = [];
        for ($i = 0; $i < $ingredients; $i++) {
            $grams[nutritionIngredient()->id] = '10.0000';
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new RecipeNutrition)->fromGrams($grams);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $narrow = $count(1);
    $wide = $count(6);

    expect($narrow)->toBe(1)->and($wide)->toBe($narrow);
});

test('treats a private ingredient like a public one', function () {
    $public = nutritionIngredient(['calories' => '100.00']);
    $private = IngredientFactory::new()->private()->create(['calories' => '100.00']);

    $facts = (new RecipeNutrition)->fromGrams([$public->id => '100.0000', $private->id => '100.0000']);

    expect($facts->totals['calories'])->toBe('200.00');
});

test('computes a nested product: product, then preparation, then preparation, then ingredient', function () {
    $a = nutritionIngredient(['calories' => '100.00']);
    $b = nutritionIngredient(['calories' => '200.00']);
    [$product, $outer, $owner] = nutritionRecipes();
    /** @var Preparation $inner */
    $inner = PreparationFactory::new()->ownedBy($owner)->create();
    nutritionAddIngredient($inner, $a, '100.00');
    nutritionAddPreparation($outer, $inner, '50.00');
    nutritionAddIngredient($outer, $b, '50.00');
    nutritionAddPreparation($product, $outer, '200.00');

    $facts = (new RecipeNutrition)->compute($product);

    // 200 g of a 100 g outer recipe = twice it: 100 g of a (via inner) + 100 g of b
    // calories = 100 x 100/100 + 100 x 200/100 = 300; weight 200 g; per 100 g = 300 / 200 x 100 = 150
    expect($facts->weightGrams)->toBe('200.0000')
        ->and($facts->totals['calories'])->toBe('300.00')
        ->and($facts->per100g['calories'])->toBe('150.00');
});

test('computes a diamond: one preparation reached through two paths', function () {
    $a = nutritionIngredient(['calories' => '100.00']);
    [$product, $left, $owner] = nutritionRecipes();
    /** @var Preparation $right */
    $right = PreparationFactory::new()->ownedBy($owner)->create();
    /** @var Preparation $shared */
    $shared = PreparationFactory::new()->ownedBy($owner)->create();
    nutritionAddIngredient($shared, $a, '100.00');
    nutritionAddPreparation($left, $shared, '100.00');
    nutritionAddPreparation($right, $shared, '100.00');
    nutritionAddPreparation($product, $left, '100.00');
    nutritionAddPreparation($product, $right, '100.00');

    $facts = (new RecipeNutrition)->compute($product);

    // 200 g of a in total: calories 200, per 100 g = 100
    expect($facts->totals['calories'])->toBe('200.00')
        ->and($facts->per100g['calories'])->toBe('100.00');
});

test('computes a preparation used at a non-trivial scale, and a preparation as the root', function () {
    $a = nutritionIngredient(['calories' => '100.00']);
    $b = nutritionIngredient(['calories' => '200.00']);
    [$product, $prep] = nutritionRecipes();
    nutritionAddIngredient($prep, $a, '100.00');
    nutritionAddIngredient($prep, $b, '300.00'); // weighs 400 g
    nutritionAddPreparation($product, $prep, '200.00'); // half of it: 50 g a, 150 g b
    nutritionAddIngredient($product, $a, '50.00');

    $facts = (new RecipeNutrition)->compute($product);

    // a = 50 + 50 = 100 g, b = 150 g; calories = 100 + 300 = 400; weight 250 g; per 100 g = 160
    expect($facts->weightGrams)->toBe('250.0000')
        ->and($facts->totals['calories'])->toBe('400.00')
        ->and($facts->per100g['calories'])->toBe('160.00');

    // The preparation alone: 100 g a + 300 g b = 100 + 600 = 700 calories over 400 g = 175 per 100 g
    $root = (new RecipeNutrition)->compute($prep);

    expect($root->totals['calories'])->toBe('700.00')
        ->and($root->per100g['calories'])->toBe('175.00');
});
