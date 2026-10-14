<?php

declare(strict_types=1);

use App\Contracts\HasRecipe;
use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\User;
use App\Recipes\RecipeCycleException;
use App\Recipes\RecipeFlattener;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function flattenerIngredient(): Ingredient
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->create();

    return $ingredient;
}

function flattenerPreparation(?User $owner = null): Preparation
{
    /** @var Preparation $preparation */
    $preparation = PreparationFactory::new()->ownedBy($owner ?? User::factory()->create())->create();

    return $preparation;
}

function flattenerProduct(?User $owner = null): Product
{
    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy($owner ?? User::factory()->create())->create();

    return $product;
}

/** A line of $quantity grams of an ingredient in a product or preparation. */
function flattenerAddIngredient(Product|Preparation $parent, Ingredient $ingredient, string $quantity): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory->withIngredient($ingredient)->create(['quantity' => $quantity]);
}

/** A line of $quantity grams of a preparation. */
function flattenerAddPreparation(Product|Preparation $parent, Preparation $component, string $quantity): void
{
    $factory = RecipeLineFactory::new();
    $factory = $parent instanceof Product ? $factory->forProduct($parent) : $factory->forPreparation($parent);
    $factory->withComponentPreparation($component)->create(['quantity' => $quantity]);
}

test('returns nothing for an empty recipe', function () {
    expect((new RecipeFlattener)->flatten(flattenerProduct()))->toBe([]);
});

test('lists the grams of each ingredient of a product, sorted by ingredient id', function () {
    $first = flattenerIngredient();
    $second = flattenerIngredient();
    $product = flattenerProduct();
    // Added in reverse id order on purpose.
    flattenerAddIngredient($product, $second, '50.00');
    flattenerAddIngredient($product, $first, '100.00');

    expect((new RecipeFlattener)->flatten($product))->toBe([
        $first->id => '100.0000',
        $second->id => '50.0000',
    ]);
});

test('converts a line counted in a unit to grams', function () {
    $ham = flattenerIngredient();
    $slice = UnitFactory::new()->create(['ingredient_id' => $ham->id, 'grams' => '40.00']);
    $product = flattenerProduct();
    RecipeLineFactory::new()->forProduct($product)->withUnit($slice)->create(['quantity' => '2.00']);

    // 2 slices x 40 g = 80 g
    expect((new RecipeFlattener)->flatten($product))->toBe([$ham->id => '80.0000']);
});

test('converts a unit of a preparation before scaling it', function () {
    $owner = User::factory()->create();
    $ingredient = flattenerIngredient();
    $cream = flattenerPreparation($owner);
    flattenerAddIngredient($cream, $ingredient, '400.00'); // weighs 400 g
    $pot = UnitFactory::new()->forPreparation($cream)->create(['grams' => '100.00']);
    $product = flattenerProduct($owner);
    RecipeLineFactory::new()->forProduct($product)->withUnit($pot)->create(['quantity' => '3.00']);

    // 3 pots x 100 g = 300 g of a 400 g cream that is all one ingredient = 300 g
    expect((new RecipeFlattener)->flatten($product))->toBe([$ingredient->id => '300.0000']);
});

test('sums an ingredient used on several lines and in a nested preparation', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $flour, '100.00');
    $product = flattenerProduct($owner);
    flattenerAddIngredient($product, $flour, '30.00');
    flattenerAddIngredient($product, $flour, '20.00');
    flattenerAddPreparation($product, $prep, '50.00'); // 50 g of a 100 g prep = 50 g flour

    expect((new RecipeFlattener)->flatten($product))->toBe([$flour->id => '100.0000']);
});

test('scales a preparation by the grams used over its own weight', function () {
    $flour = flattenerIngredient();
    $sugar = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $flour, '300.00');
    flattenerAddIngredient($prep, $sugar, '700.00'); // weighs 1000 g
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $prep, '200.00');

    // 200/1000 = 20%: flour 60 g, sugar 140 g
    expect((new RecipeFlattener)->flatten($product))->toBe([
        $flour->id => '60.0000',
        $sugar->id => '140.0000',
    ]);
});

test('works with a preparation as the root', function () {
    $flour = flattenerIngredient();
    $prep = flattenerPreparation();
    flattenerAddIngredient($prep, $flour, '120.50');

    expect((new RecipeFlattener)->flatten($prep))->toBe([$flour->id => '120.5000']);
});

test('rounds a one third scaling to four decimals', function () {
    $a = flattenerIngredient();
    $b = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $a, '1.00');
    flattenerAddIngredient($prep, $b, '2.00'); // weighs 3 g
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $prep, '1.00');

    // a: 1/3 = 0.33333333 -> 0.3333 ; b: 2/3 = 0.66666666 -> 0.6667
    expect((new RecipeFlattener)->flatten($product))->toBe([
        $a->id => '0.3333',
        $b->id => '0.6667',
    ]);
});

test('rounds half up at the fourth decimal', function () {
    $a = flattenerIngredient();
    $b = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $a, '1.00');
    flattenerAddIngredient($prep, $b, '31.00'); // weighs 32 g
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $prep, '1.00');

    // a: 1/32 = 0.03125 -> 0.0313 ; b: 31/32 = 0.96875 -> 0.9688
    expect((new RecipeFlattener)->flatten($product))->toBe([
        $a->id => '0.0313',
        $b->id => '0.9688',
    ]);
});

test('flattens preparations nested three levels deep', function () {
    $x = flattenerIngredient();
    $y = flattenerIngredient();
    $z = flattenerIngredient();
    $owner = User::factory()->create();
    $outer = flattenerPreparation($owner);
    $inner = flattenerPreparation($owner);
    flattenerAddIngredient($inner, $y, '100.00');
    flattenerAddIngredient($inner, $z, '100.00'); // weighs 200 g
    flattenerAddIngredient($outer, $x, '50.00');
    flattenerAddPreparation($outer, $inner, '50.00'); // weighs 100 g
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $outer, '100.00');

    // outer is used whole: x 50 g + 50 g of inner (25% of 200 g): y 25 g, z 25 g
    expect((new RecipeFlattener)->flatten($product))->toBe([
        $x->id => '50.0000',
        $y->id => '25.0000',
        $z->id => '25.0000',
    ]);
});

test('counts the same preparation used on two lines of one recipe', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $flour, '100.00');
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $prep, '50.00');
    flattenerAddPreparation($product, $prep, '150.00');

    expect((new RecipeFlattener)->flatten($product))->toBe([$flour->id => '200.0000']);
});

test('does not mistake a diamond for a cycle', function () {
    [$x, $y, $z, $w] = [flattenerIngredient(), flattenerIngredient(), flattenerIngredient(), flattenerIngredient()];
    $owner = User::factory()->create();
    [$b, $c, $d] = [flattenerPreparation($owner), flattenerPreparation($owner), flattenerPreparation($owner)];
    flattenerAddIngredient($d, $z, '100.00');
    flattenerAddIngredient($d, $w, '100.00'); // D weighs 200 g
    flattenerAddIngredient($b, $x, '50.00');
    flattenerAddPreparation($b, $d, '50.00'); // B weighs 100 g
    flattenerAddIngredient($c, $y, '100.00');
    flattenerAddPreparation($c, $d, '100.00'); // C weighs 200 g
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $b, '100.00'); // all of B
    flattenerAddPreparation($product, $c, '100.00'); // half of C

    // B: x 50, D 50 g (z 25, w 25). C halved: y 50, D 50 g (z 25, w 25).
    expect((new RecipeFlattener)->flatten($product))->toBe([
        $x->id => '50.0000',
        $y->id => '50.0000',
        $z->id => '50.0000',
        $w->id => '50.0000',
    ]);
});

test('ignores a preparation that weighs nothing instead of dividing by zero', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $empty = flattenerPreparation($owner);
    $product = flattenerProduct($owner);
    flattenerAddIngredient($product, $flour, '10.00');
    flattenerAddPreparation($product, $empty, '100.00');

    expect((new RecipeFlattener)->flatten($product))->toBe([$flour->id => '10.0000']);
});

test('fails with the preparations involved when the data contains a cycle', function () {
    $owner = User::factory()->create();
    $jam = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Jam']);
    $glaze = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Glaze']);
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $jam, '10.00');
    // Bulk inserts skip the cycle guard: the only way to get a cycle into the data.
    foreach ([[$jam, $glaze], [$glaze, $jam]] as [$parent, $component]) {
        RecipeLine::query()->insert([
            'parent_preparation_id' => $parent->id,
            'component_preparation_id' => $component->id,
            'position' => 0,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(fn () => (new RecipeFlattener)->flatten($product))
        ->toThrow(RecipeCycleException::class, 'Jam');
    expect(fn () => (new RecipeFlattener)->flatten($product))
        ->toThrow(RecipeCycleException::class, 'Glaze');
});

/**
 * A product using $width preparations, each using $width preparations, each holding one
 * ingredient counted in a unit: depth 3, with the width as the only variable.
 */
function flattenerWideTree(int $width): Product
{
    $owner = User::factory()->create();
    $product = flattenerProduct($owner);
    $unit = UnitFactory::new()->create(['grams' => '10.00']);

    for ($i = 0; $i < $width; $i++) {
        $middle = flattenerPreparation($owner);
        flattenerAddPreparation($product, $middle, '10.00');
        for ($j = 0; $j < $width; $j++) {
            $leaf = flattenerPreparation($owner);
            flattenerAddPreparation($middle, $leaf, '10.00');
            RecipeLineFactory::new()->forPreparation($leaf)->withUnit($unit)->create(['quantity' => '2.00']);
        }
    }

    return $product;
}

function flattenerQueryCount(Product $product): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    (new RecipeFlattener)->flatten($product);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('runs the same number of queries for a wide tree as for a narrow one', function () {
    $narrow = flattenerQueryCount(flattenerWideTree(1));
    $wide = flattenerQueryCount(flattenerWideTree(4));

    expect($wide)->toBe($narrow);
});

test('rounds only once at the end, after summing the scaled lines', function () {
    $a = flattenerIngredient();
    $b = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $a, '1.00');
    flattenerAddIngredient($prep, $b, '2.00'); // weighs 3 g
    $product = flattenerProduct($owner);
    foreach (range(1, 3) as $ignored) {
        flattenerAddPreparation($product, $prep, '1.00');
    }

    // Three times a third of 1 g is 1 g (0.9999 if each third were rounded early); b is 2 g.
    expect((new RecipeFlattener)->flatten($product))->toBe([
        $a->id => '1.0000',
        $b->id => '2.0000',
    ]);
});

test('scales a preparation used in more grams than its own weight', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $flour, '100.00');
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $prep, '250.00');

    expect((new RecipeFlattener)->flatten($product))->toBe([$flour->id => '250.0000']);
});

test('flattens a chain of five nested preparations', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $chain = array_map(fn () => flattenerPreparation($owner), range(1, 5));
    flattenerAddIngredient($chain[4], $flour, '80.00');
    for ($i = 0; $i < 4; $i++) {
        flattenerAddPreparation($chain[$i], $chain[$i + 1], '10.00');
    }
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $chain[0], '5.00');

    // Every level weighs 10 g except the last (80 g of flour). Using 5 g of the first level
    // takes half of its 10 g, which is half of 10 g of the next level, and so on: 5 g of flour.
    expect((new RecipeFlattener)->flatten($product))->toBe([$flour->id => '5.0000']);
});

test('ignores a zero-weight preparation nested inside another preparation', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $empty = flattenerPreparation($owner);
    $outer = flattenerPreparation($owner);
    flattenerAddIngredient($outer, $flour, '10.00');
    flattenerAddPreparation($outer, $empty, '90.00');
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $outer, '10.00');

    // Outer weighs 100 g (10 g flour + 90 g of nothing); using 10 g of it gives 1 g flour.
    expect((new RecipeFlattener)->flatten($product))->toBe([$flour->id => '1.0000']);
});

test('returns nothing for a preparation root with no lines', function () {
    expect((new RecipeFlattener)->flatten(flattenerPreparation()))->toBe([]);
});

test('multiplies a fractional quantity by a unit weight', function () {
    $ham = flattenerIngredient();
    $slice = UnitFactory::new()->create(['ingredient_id' => $ham->id, 'grams' => '33.33']);
    $product = flattenerProduct();
    RecipeLineFactory::new()->forProduct($product)->withUnit($slice)->create(['quantity' => '1.50']);

    // 1.5 x 33.33 = 49.995 g, rounded half up to 49.9950
    expect((new RecipeFlattener)->flatten($product))->toBe([$ham->id => '49.9950']);
});

/** Inserts lines straight into the table, skipping the cycle guard, like a bulk write would. */
function flattenerInsertPreparationLink(Preparation $parent, Preparation $component): void
{
    RecipeLine::query()->insert([
        'parent_preparation_id' => $parent->id,
        'component_preparation_id' => $component->id,
        'position' => 0,
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// A preparation using itself cannot be inserted: a database check constraint forbids it.
test('fails on a cycle of three preparations reached from a product', function () {
    $owner = User::factory()->create();
    $a = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Alpha']);
    $b = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Beta']);
    $c = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Gamma']);
    flattenerInsertPreparationLink($a, $b);
    flattenerInsertPreparationLink($b, $c);
    flattenerInsertPreparationLink($c, $a);
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $a, '10.00');

    expect(fn () => (new RecipeFlattener)->flatten($product))
        ->toThrow(RecipeCycleException::class, 'Alpha');
});

test('fails on a cycle when the root is itself one of the preparations in the cycle', function () {
    $owner = User::factory()->create();
    $jam = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Jam']);
    $glaze = PreparationFactory::new()->ownedBy($owner)->create(['name' => 'Glaze']);
    flattenerInsertPreparationLink($jam, $glaze);
    flattenerInsertPreparationLink($glaze, $jam);

    expect(fn () => (new RecipeFlattener)->flatten($jam))->toThrow(RecipeCycleException::class);
});

test('gives the same answer when flattening twice with the same instance', function () {
    $flour = flattenerIngredient();
    $owner = User::factory()->create();
    $prep = flattenerPreparation($owner);
    flattenerAddIngredient($prep, $flour, '3.00');
    $product = flattenerProduct($owner);
    flattenerAddPreparation($product, $prep, '1.00');
    $flattener = new RecipeFlattener;

    expect($flattener->flatten($product))->toBe($flattener->flatten($product));
});

test('runs the same number of queries for a wide and a narrow tree one level deep', function () {
    $owner = User::factory()->create();
    $count = function (int $width) use ($owner): int {
        $product = flattenerProduct($owner);
        for ($i = 0; $i < $width; $i++) {
            $prep = flattenerPreparation($owner);
            flattenerAddIngredient($prep, flattenerIngredient(), '10.00');
            flattenerAddPreparation($product, $prep, '5.00');
        }

        return flattenerQueryCount($product);
    };

    expect($count(8))->toBe($count(1));
});

/**
 * The amount guard can only be reached with raw strings: real models cast quantities to
 * "decimal:2", which always yields a clean number. So the lines are stubs that return their
 * attributes untouched, handed to the flattener through a stub recipe (no database rows).
 */
function flattenerStubRecipe(string $quantity): HasRecipe
{
    $line = new class extends RecipeLine
    {
        public string $rawQuantity = '';

        public function getAttribute($key): mixed
        {
            return match ($key) {
                'quantity' => $this->rawQuantity,
                'ingredient_id' => 1,
                'unit' => null,
                default => null,
            };
        }
    };
    $line->rawQuantity = $quantity;

    $relation = new class(Product::query(), new Product, 'product_id', 'id') extends HasMany
    {
        /** @var Collection<int, RecipeLine> */
        public Collection $stubLines;

        public function get($columns = ['*']): Collection
        {
            return $this->stubLines;
        }
    };
    $relation->stubLines = new Collection([$line]);

    return new class($relation) implements HasRecipe
    {
        public function __construct(private HasMany $relation) {}

        public function recipeLines(): HasMany
        {
            return $this->relation;
        }
    };
}

test('rejects an amount BCMath cannot read, with a clear message', function (string $amount) {
    expect(fn () => (new RecipeFlattener)->flatten(flattenerStubRecipe($amount)))
        ->toThrow(UnexpectedValueException::class, 'non-numeric amount');
})->with(['1e5', ' 12', '1.', '']);

test('accepts plain decimal amounts', function (string $amount, string $expected) {
    $result = (new RecipeFlattener)->flatten(flattenerStubRecipe($amount));

    expect($result)->toBe([1 => $expected]);
})->with([
    ['12', '12.0000'],
    ['0.5', '0.5000'],
    ['12.0000', '12.0000'],
]);

test('does not reject a negative amount at the guard', function () {
    // The DB forbids it (quantity > 0); only the guard's shape check is covered here.
    expect(fn () => (new RecipeFlattener)->flatten(flattenerStubRecipe('-3')))->not->toThrow(UnexpectedValueException::class);
});
