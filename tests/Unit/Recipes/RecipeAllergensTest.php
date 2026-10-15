<?php

declare(strict_types=1);

use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\User;
use App\Recipes\AllergenReport;
use App\Recipes\RecipeAllergens;
use Database\Factories\AllergenFactory;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function allergenByCode(string $code): Allergen
{
    $existing = Allergen::query()->firstWhere('code', $code);
    if ($existing !== null) {
        return $existing;
    }

    /** @var Allergen $allergen */
    $allergen = AllergenFactory::new()->create(['code' => $code, 'label_fr' => $code]);

    return $allergen;
}

/**
 * @param  list<string>  $allergens  codes the ingredient contains
 * @param  list<string>  $traces  codes the ingredient may contain traces of
 */
function allergenIngredient(array $allergens = [], array $traces = [], bool $private = false): Ingredient
{
    $factory = IngredientFactory::new();
    if ($private) {
        // Private to the owner shared by every recipe of the test, so their lines may use it.
        $owner = allergenOwner();
        $factory = $factory->state(fn (array $attributes): array => [
            'owner_id' => $owner->id,
            'slug' => Ingredient::buildSlug($attributes['name'], $attributes['storage'], $owner->id),
        ]);
    }
    /** @var Ingredient $ingredient */
    $ingredient = $factory->create();

    foreach ($allergens as $code) {
        $ingredient->allergens()->attach(allergenByCode($code));
    }
    foreach ($traces as $code) {
        $ingredient->allergenTraces()->attach(allergenByCode($code));
    }

    return $ingredient;
}

function allergenLine(Product|Preparation $parent, Ingredient|Preparation $component, string $quantity = '100.00'): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory = $component instanceof Ingredient ? $factory->withIngredient($component) : $factory->withComponentPreparation($component);
    $factory->create(['quantity' => $quantity]);
}

/** The single user owning every recipe of a test (recipes may only use preparations of their owner). */
function allergenOwner(): User
{
    return User::query()->first() ?? User::factory()->create();
}

function allergenProduct(): Product
{
    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy(allergenOwner())->create();

    return $product;
}

function allergenPreparation(): Preparation
{
    /** @var Preparation $preparation */
    $preparation = PreparationFactory::new()->ownedBy(allergenOwner())->create();

    return $preparation;
}

/** Runs $work and returns how many queries it made. */
function allergenQueryCount(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

test('returns two empty lists for no ingredient at all', function () {
    $report = (new RecipeAllergens)->fromIngredientIds([]);

    expect($report)->toBeInstanceOf(AllergenReport::class)
        ->and($report->allergens)->toBe([])
        ->and($report->traces)->toBe([]);
});

test('returns two empty lists when the ingredients have no allergens', function () {
    $plain = allergenIngredient();

    $report = (new RecipeAllergens)->fromIngredientIds([$plain->id]);

    expect($report->allergens)->toBe([])->and($report->traces)->toBe([]);
});

test('gives the deduplicated, sorted union of the allergens of the ingredients', function () {
    $first = allergenIngredient(['milk', 'eggs']);
    $second = allergenIngredient(['eggs', 'celery']);

    $report = (new RecipeAllergens)->fromIngredientIds([$first->id, $second->id]);

    expect($report->allergens)->toBe(['celery', 'eggs', 'milk']);
});

test('gives the deduplicated, sorted union of the traces of the ingredients', function () {
    $first = allergenIngredient([], ['soy', 'nuts']);
    $second = allergenIngredient([], ['nuts']);

    $report = (new RecipeAllergens)->fromIngredientIds([$first->id, $second->id]);

    expect($report->traces)->toBe(['nuts', 'soy']);
});

test('does not list as a trace an allergen that is already contained', function () {
    // One ingredient has milk both as allergen and as trace.
    $both = allergenIngredient(['milk'], ['milk', 'soy']);
    // Another one has traces of eggs only.
    $other = allergenIngredient([], ['eggs']);
    // A third has traces of milk only, but milk is contained by $both.
    $trace = allergenIngredient([], ['milk']);

    $report = (new RecipeAllergens)->fromIngredientIds([$both->id, $other->id, $trace->id]);

    expect($report->allergens)->toBe(['milk'])
        ->and($report->traces)->toBe(['eggs', 'soy']);
});

test('ignores the allergens of ingredients that are not asked for', function () {
    $used = allergenIngredient(['milk']);
    allergenIngredient(['eggs'], ['soy']);

    $report = (new RecipeAllergens)->fromIngredientIds([$used->id]);

    expect($report->allergens)->toBe(['milk'])->and($report->traces)->toBe([]);
});

test('loads with the same number of queries whatever the number of ingredients', function () {
    $count = function (int $ingredients): int {
        $ids = [];
        for ($i = 0; $i < $ingredients; $i++) {
            $ids[] = allergenIngredient(['milk'], ['soy'])->id;
        }

        return allergenQueryCount(fn () => (new RecipeAllergens)->fromIngredientIds($ids));
    };

    $narrow = $count(1);
    $wide = $count(12);

    expect($narrow)->toBeLessThanOrEqual(3)->and($wide)->toBe($narrow);
});

test('computes the allergens of a nested product, through a deep preparation', function () {
    $product = allergenProduct();
    $outer = allergenPreparation();
    $inner = allergenPreparation();
    allergenLine($product, allergenIngredient(['gluten']));
    allergenLine($product, $outer);
    allergenLine($outer, allergenIngredient([], ['soy']));
    allergenLine($outer, $inner);
    allergenLine($inner, allergenIngredient(['milk'], ['gluten', 'nuts']));

    $report = (new RecipeAllergens)->compute($product);

    expect($report->allergens)->toBe(['gluten', 'milk'])
        ->and($report->traces)->toBe(['nuts', 'soy']);
});

test('computes the allergens of a preparation as root, with a private ingredient', function () {
    $preparation = allergenPreparation();
    allergenLine($preparation, allergenIngredient(['eggs'], [], private: true));
    allergenLine($preparation, allergenIngredient(['celery']));

    $report = (new RecipeAllergens)->compute($preparation);

    expect($report->allergens)->toBe(['celery', 'eggs']);
});

test('handles a diamond, a preparation reached by two paths', function () {
    $product = allergenProduct();
    $left = allergenPreparation();
    $right = allergenPreparation();
    $shared = allergenPreparation();
    allergenLine($product, $left);
    allergenLine($product, $right);
    allergenLine($left, $shared);
    allergenLine($right, $shared);
    allergenLine($shared, allergenIngredient(['fish']));

    expect((new RecipeAllergens)->compute($product)->allergens)->toBe(['fish']);
});

test('counts an ingredient present in a tiny quantity', function () {
    $product = allergenProduct();
    $preparation = allergenPreparation();
    allergenLine($product, $preparation, '0.01');
    allergenLine($preparation, allergenIngredient(['mustard']), '1.00');
    allergenLine($preparation, allergenIngredient(['sesame']), '10000.00');

    // Mustard is 0.01 g x 1 / 10001 g = 0.000001 g, which rounds to 0.0000 g, and still counts.
    expect((new RecipeAllergens)->compute($product)->allergens)->toBe(['mustard', 'sesame']);
});

test('returns two empty lists for an empty recipe', function () {
    $report = (new RecipeAllergens)->compute(allergenProduct());

    expect($report->allergens)->toBe([])->and($report->traces)->toBe([]);
});

test('keeps the same query count for a wide and a narrow tree', function () {
    $tree = function (int $width): Product {
        $product = allergenProduct();
        $prep = allergenPreparation();
        allergenLine($product, $prep);
        for ($i = 0; $i < $width; $i++) {
            allergenLine($prep, allergenIngredient(['milk'], ['soy']));
        }

        return $product;
    };

    $narrowTree = $tree(1);
    $wideTree = $tree(8);
    $narrow = allergenQueryCount(fn () => (new RecipeAllergens)->compute($narrowTree));
    $wide = allergenQueryCount(fn () => (new RecipeAllergens)->compute($wideTree));

    expect($wide)->toBe($narrow);
});
