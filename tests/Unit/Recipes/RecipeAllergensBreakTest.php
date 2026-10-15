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

// QA attack tests. Helpers are prefixed "qaAllergen" so they never clash with RecipeAllergensTest.php.
uses(TestCase::class, RefreshDatabase::class);

function qaAllergenCode(string $code): Allergen
{
    return Allergen::query()->firstWhere('code', $code)
        ?? AllergenFactory::new()->create(['code' => $code, 'label_fr' => $code]);
}

/**
 * @param  list<string>  $allergens
 * @param  list<string>  $traces
 */
function qaAllergenIngredient(array $allergens = [], array $traces = [], bool $private = false): Ingredient
{
    $factory = IngredientFactory::new();
    if ($private) {
        $owner = qaAllergenOwner();
        $factory = $factory->state(fn (array $a): array => [
            'owner_id' => $owner->id,
            'slug' => Ingredient::buildSlug($a['name'], $a['storage'], $owner->id),
        ]);
    }
    /** @var Ingredient $ingredient */
    $ingredient = $factory->create();
    foreach ($allergens as $code) {
        $ingredient->allergens()->attach(qaAllergenCode($code));
    }
    foreach ($traces as $code) {
        $ingredient->allergenTraces()->attach(qaAllergenCode($code));
    }

    return $ingredient;
}

function qaAllergenOwner(): User
{
    return User::query()->first() ?? User::factory()->create();
}

function qaAllergenProduct(): Product
{
    /** @var Product $p */
    $p = ProductFactory::new()->ownedBy(qaAllergenOwner())->create();

    return $p;
}

function qaAllergenPreparation(): Preparation
{
    /** @var Preparation $p */
    $p = PreparationFactory::new()->ownedBy(qaAllergenOwner())->create();

    return $p;
}

function qaAllergenLine(Product|Preparation $parent, Ingredient|Preparation $component, string $quantity = '100.00'): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory = $component instanceof Ingredient ? $factory->withIngredient($component) : $factory->withComponentPreparation($component);
    $factory->create(['quantity' => $quantity]);
}

function qaAllergenQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

test('lists an allergen once when several ingredients of one recipe carry it', function () {
    $product = qaAllergenProduct();
    $prep = qaAllergenPreparation();
    qaAllergenLine($product, qaAllergenIngredient(['milk']));
    qaAllergenLine($product, $prep);
    qaAllergenLine($prep, qaAllergenIngredient(['milk', 'eggs']));
    qaAllergenLine($prep, qaAllergenIngredient(['milk']));

    expect((new RecipeAllergens)->compute($product)->allergens)->toBe(['eggs', 'milk']);
});

test('removes a trace found on an ingredient discovered before the one containing the allergen', function () {
    $traceFirst = qaAllergenIngredient([], ['soy']);
    $containsLater = qaAllergenIngredient(['soy']);

    $report = (new RecipeAllergens)->fromIngredientIds([$traceFirst->id, $containsLater->id]);

    expect($report->allergens)->toBe(['soy'])->and($report->traces)->toBe([]);
});

test('removes a trace found on an ingredient discovered after the one containing the allergen', function () {
    $containsFirst = qaAllergenIngredient(['soy']);
    $traceLater = qaAllergenIngredient([], ['soy']);

    $report = (new RecipeAllergens)->fromIngredientIds([$containsFirst->id, $traceLater->id]);

    expect($report->allergens)->toBe(['soy'])->and($report->traces)->toBe([]);
});

test('returns several traces sorted by code and reindexed from zero', function () {
    // Allergen "celery" sits between traces, and is contained, so the diff leaves a gap in the keys.
    $a = qaAllergenIngredient(['celery'], ['sesame', 'celery', 'almond', 'lupin']);

    $report = (new RecipeAllergens)->fromIngredientIds([$a->id]);

    expect($report->traces)->toBe(['almond', 'lupin', 'sesame'])
        ->and(array_is_list($report->traces))->toBeTrue()
        ->and(array_is_list($report->allergens))->toBeTrue();
});

test('keeps plain-code ordering stable for codes with digits and underscores', function () {
    $a = qaAllergenIngredient(['tree_nuts', 'sulphites', 'b2', 'a10']);

    $report = (new RecipeAllergens)->fromIngredientIds([$a->id]);

    expect($report->allergens)->toBe(['a10', 'b2', 'sulphites', 'tree_nuts']);
});

test('sorts mixed-case codes in plain byte order, whatever the database collation', function () {
    $a = qaAllergenIngredient(['beta', 'Zeta', 'alpha']);

    $report = (new RecipeAllergens)->fromIngredientIds([$a->id]);

    // Byte order (PHP sort): uppercase before lowercase.
    expect($report->allergens)->toBe(['Zeta', 'alpha', 'beta']);
});

test('ignores ingredients without allergens next to ingredients with some', function () {
    $plain = qaAllergenIngredient();
    $withSome = qaAllergenIngredient(['fish'], ['nuts']);

    $report = (new RecipeAllergens)->fromIngredientIds([$plain->id, $withSome->id]);

    expect($report->allergens)->toBe(['fish'])->and($report->traces)->toBe(['nuts']);
});

test('gives the same result for an ingredient reached twice through two preparations', function () {
    $product = qaAllergenProduct();
    $left = qaAllergenPreparation();
    $right = qaAllergenPreparation();
    $shared = qaAllergenIngredient(['lupin'], ['soy']);
    qaAllergenLine($product, $left);
    qaAllergenLine($product, $right);
    qaAllergenLine($left, $shared);
    qaAllergenLine($right, $shared);
    qaAllergenLine($product, $shared);

    $report = (new RecipeAllergens)->compute($product);

    expect($report->allergens)->toBe(['lupin'])->and($report->traces)->toBe(['soy']);
});

test('finds an allergen that sits only at the bottom of five nested preparations', function () {
    $product = qaAllergenProduct();
    $levels = [];
    for ($i = 0; $i < 5; $i++) {
        $levels[$i] = qaAllergenPreparation();
    }
    qaAllergenLine($product, $levels[0]);
    for ($i = 0; $i < 4; $i++) {
        qaAllergenLine($levels[$i], $levels[$i + 1]);
    }
    qaAllergenLine($levels[4], qaAllergenIngredient(['mollusc'], ['fish']));

    $report = (new RecipeAllergens)->compute($product);

    expect($report->allergens)->toBe(['mollusc'])->and($report->traces)->toBe(['fish']);
});

test('counts an ingredient whose quantity is the smallest possible next to a huge one', function () {
    $product = qaAllergenProduct();
    $prep = qaAllergenPreparation();
    qaAllergenLine($product, $prep, '0.01');
    qaAllergenLine($prep, qaAllergenIngredient([], ['mustard']), '0.01');
    qaAllergenLine($prep, qaAllergenIngredient(['sesame']), '99999999.99');

    $report = (new RecipeAllergens)->compute($product);

    expect($report->allergens)->toBe(['sesame'])->and($report->traces)->toBe(['mustard']);
});

test('returns two empty lists for an empty preparation', function () {
    $report = (new RecipeAllergens)->compute(qaAllergenPreparation());

    expect($report->allergens)->toBe([])->and($report->traces)->toBe([]);
});

test('computes the allergens of a product as root with a private ingredient of its owner', function () {
    $product = qaAllergenProduct();
    qaAllergenLine($product, qaAllergenIngredient(['eggs'], ['soy'], private: true));
    qaAllergenLine($product, qaAllergenIngredient(['celery']));

    $report = (new RecipeAllergens)->compute($product);

    expect($report->allergens)->toBe(['celery', 'eggs'])->and($report->traces)->toBe(['soy']);
});

test('treats a private and a public ingredient with the same allergens identically', function () {
    $public = qaAllergenIngredient(['milk'], ['soy']);
    $private = qaAllergenIngredient(['milk'], ['soy'], private: true);

    $engine = new RecipeAllergens;

    expect($engine->fromIngredientIds([$private->id]))->toEqual($engine->fromIngredientIds([$public->id]));
});

test('ignores an unknown ingredient id instead of failing', function () {
    $known = qaAllergenIngredient(['milk']);

    $report = (new RecipeAllergens)->fromIngredientIds([$known->id, 999999999]);

    expect($report->allergens)->toBe(['milk'])->and($report->traces)->toBe([]);
});

test('returns two empty lists when only unknown ingredient ids are given', function () {
    $report = (new RecipeAllergens)->fromIngredientIds([999999998, 999999999]);

    expect($report->allergens)->toBe([])->and($report->traces)->toBe([]);
});

test('gives the same result when the id list holds duplicates', function () {
    $a = qaAllergenIngredient(['milk'], ['soy']);

    $report = (new RecipeAllergens)->fromIngredientIds([$a->id, $a->id, $a->id]);

    expect($report->allergens)->toBe(['milk'])->and($report->traces)->toBe(['soy']);
});

test('takes at most two queries for five hundred ingredient ids', function () {
    $ids = [];
    $milk = qaAllergenCode('milk');
    $soy = qaAllergenCode('soy');
    $rows = IngredientFactory::new()->count(500)->create();
    foreach ($rows as $ingredient) {
        $ids[] = $ingredient->id;
    }
    DB::table('ingredient_allergen')->insert(array_map(fn (int $id): array => ['ingredient_id' => $id, 'allergen_id' => $milk->id], $ids));
    DB::table('ingredient_allergen_trace')->insert(array_map(fn (int $id): array => ['ingredient_id' => $id, 'allergen_id' => $soy->id], $ids));

    $report = null;
    $queries = qaAllergenQueries(function () use ($ids, &$report) {
        $report = (new RecipeAllergens)->fromIngredientIds($ids);
    });

    expect($queries)->toBeLessThanOrEqual(2)
        ->and($report->allergens)->toBe(['milk'])
        ->and($report->traces)->toBe(['soy']);
});

test('makes no query at all for an empty id list', function () {
    expect(qaAllergenQueries(fn () => (new RecipeAllergens)->fromIngredientIds([])))->toBe(0);
});

test('returns a report that cannot be modified', function () {
    $report = (new RecipeAllergens)->fromIngredientIds([qaAllergenIngredient(['milk'])->id]);

    expect(fn () => $report->allergens = [])->toThrow(Error::class)
        ->and(fn () => $report->traces = ['x'])->toThrow(Error::class)
        ->and($report)->toBeInstanceOf(AllergenReport::class)
        ->and((new ReflectionClass($report))->isReadOnly())->toBeTrue();
});

test('does not change a second computation after the first one on the same instance', function () {
    $engine = new RecipeAllergens;
    $first = qaAllergenProduct();
    $second = qaAllergenProduct();
    qaAllergenLine($first, qaAllergenIngredient(['milk']));
    qaAllergenLine($second, qaAllergenIngredient(['eggs']));

    $engine->compute($first);

    expect($engine->compute($second)->allergens)->toBe(['eggs']);
});
