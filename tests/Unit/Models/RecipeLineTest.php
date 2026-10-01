<?php

declare(strict_types=1);

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\IngredientFactory;
use Database\Factories\PreparationFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Same local binding as IngredientTest.php: these tests need a real (Postgres) database.
uses(TestCase::class, RefreshDatabase::class);

/**
 * A private ingredient together with its owner.
 *
 * @return array{0: Ingredient, 1: User}
 */
function recipeLinePrivateIngredientWithOwner(): array
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->private()->create();

    return [$ingredient, User::query()->findOrFail($ingredient->owner_id)];
}

function recipeLineProductOwnedBy(User $owner): Product
{
    /** @var Product $product */
    $product = ProductFactory::new()->ownedBy($owner)->create();

    return $product;
}

function recipeLinePreparationOwnedBy(User $owner): Preparation
{
    /** @var Preparation $preparation */
    $preparation = PreparationFactory::new()->ownedBy($owner)->create();

    return $preparation;
}

// --- ordering and quantity storage -----------------------------------------------------

test('lists a recipe\'s lines by position, then by creation order for equal positions', function () {
    $product = ProductFactory::new()->create();

    $third = RecipeLineFactory::new()->forProduct($product)->create(['position' => 2]);
    $first = RecipeLineFactory::new()->forProduct($product)->create(['position' => 1]);
    $second = RecipeLineFactory::new()->forProduct($product)->create(['position' => 1]);
    // Another recipe's line must not show up.
    RecipeLineFactory::new()->create(['position' => 0]);

    expect($product->recipeLines()->pluck('id')->all())
        ->toBe([$first->id, $second->id, $third->id]);
});

test('lists a preparation\'s lines by position too', function () {
    $preparation = PreparationFactory::new()->create();

    $second = RecipeLineFactory::new()->forPreparation($preparation)->create(['position' => 5]);
    $first = RecipeLineFactory::new()->forPreparation($preparation)->create(['position' => 0]);

    expect($preparation->recipeLines()->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('stores the quantity as an exact two-decimal value', function () {
    $line = RecipeLineFactory::new()->create(['quantity' => 2.5]);

    expect(RecipeLine::query()->findOrFail($line->id)->quantity)->toBe('2.50');
});

// --- shape rules enforced by the database ---------------------------------------------
// Postgres aborts the transaction after a constraint error, so only the exception is
// asserted in these tests, with no follow-up query.

test('refuses at the database level a zero quantity', function () {
    expect(fn () => RecipeLineFactory::new()->create(['quantity' => 0]))
        ->toThrow(QueryException::class, 'recipe_lines_quantity_positive');
});

test('refuses at the database level a negative quantity', function () {
    expect(fn () => RecipeLineFactory::new()->create(['quantity' => -1]))
        ->toThrow(QueryException::class, 'recipe_lines_quantity_positive');
});

test('refuses at the database level a line belonging to both a preparation and a product', function () {
    $owner = User::factory()->create();
    $preparation = recipeLinePreparationOwnedBy($owner);
    $product = recipeLineProductOwnedBy($owner);

    expect(fn () => RecipeLineFactory::new()->create([
        'parent_preparation_id' => $preparation->id,
        'product_id' => $product->id,
    ]))->toThrow(QueryException::class, 'recipe_lines_one_parent');
});

test('refuses at the database level a line with no recipe', function () {
    expect(fn () => RecipeLineFactory::new()->create([
        'parent_preparation_id' => null,
        'product_id' => null,
    ]))->toThrow(QueryException::class, 'recipe_lines_one_parent');
});

test('refuses at the database level a line using both an ingredient and a preparation', function () {
    $owner = User::factory()->create();
    $product = recipeLineProductOwnedBy($owner);
    $component = recipeLinePreparationOwnedBy($owner);

    expect(fn () => RecipeLineFactory::new()->forProduct($product)->create([
        'ingredient_id' => IngredientFactory::new()->create()->id,
        'component_preparation_id' => $component->id,
    ]))->toThrow(QueryException::class, 'recipe_lines_one_component');
});

test('refuses at the database level a line with nothing in it', function () {
    expect(fn () => RecipeLineFactory::new()->create([
        'ingredient_id' => null,
        'component_preparation_id' => null,
    ]))->toThrow(QueryException::class, 'recipe_lines_one_component');
});

test('refuses at the database level a preparation listing itself as an ingredient', function () {
    $preparation = PreparationFactory::new()->create();

    expect(fn () => RecipeLineFactory::new()->forPreparation($preparation)->create([
        'ingredient_id' => null,
        'component_preparation_id' => $preparation->id,
    ]))->toThrow(QueryException::class, 'recipe_lines_no_self_reference');
});

// --- visibility rules: ingredients -----------------------------------------------------

test("refuses another user's private ingredient in a recipe", function () {
    [$ingredient] = recipeLinePrivateIngredientWithOwner();
    $product = recipeLineProductOwnedBy(User::factory()->create());

    expect(fn () => RecipeLineFactory::new()->forProduct($product)->withIngredient($ingredient)->create())
        ->toThrow(LogicException::class);

    expect(RecipeLine::query()->count())->toBe(0);
});

test('accepts a public ingredient in anyone\'s recipe', function () {
    $ingredient = IngredientFactory::new()->create();
    $product = recipeLineProductOwnedBy(User::factory()->create());

    $line = RecipeLineFactory::new()->forProduct($product)->withIngredient($ingredient)->create();

    expect($line->exists)->toBeTrue();
});

test('accepts the recipe owner\'s own private ingredient', function () {
    [$ingredient, $owner] = recipeLinePrivateIngredientWithOwner();
    $preparation = recipeLinePreparationOwnedBy($owner);

    $line = RecipeLineFactory::new()->forPreparation($preparation)->withIngredient($ingredient)->create();

    expect($line->exists)->toBeTrue();
});

test("refuses switching an existing line to another user's private ingredient", function () {
    $line = RecipeLineFactory::new()->create();
    [$privateIngredient] = recipeLinePrivateIngredientWithOwner();

    $line->ingredient_id = $privateIngredient->id;

    expect(fn () => $line->save())->toThrow(LogicException::class);
    expect(RecipeLine::query()->findOrFail($line->id)->ingredient_id)->not->toBe($privateIngredient->id);
});

// --- visibility rules: component preparations ------------------------------------------

test("refuses another user's preparation as a component", function () {
    $foreignPreparation = PreparationFactory::new()->create();
    $product = recipeLineProductOwnedBy(User::factory()->create());

    expect(fn () => RecipeLineFactory::new()->forProduct($product)->withComponentPreparation($foreignPreparation)->create())
        ->toThrow(LogicException::class);

    expect(RecipeLine::query()->count())->toBe(0);
});

test('accepts the recipe owner\'s own preparation as a component', function () {
    $owner = User::factory()->create();
    $component = recipeLinePreparationOwnedBy($owner);
    $product = recipeLineProductOwnedBy($owner);

    $line = RecipeLineFactory::new()->forProduct($product)->withComponentPreparation($component)->create();

    expect($line->exists)->toBeTrue()
        ->and($line->component_preparation_id)->toBe($component->id);
});

test('accepts one of the owner\'s preparations inside another of their preparations', function () {
    $line = RecipeLineFactory::new()->forPreparation()->withComponentPreparation()->create();

    expect($line->exists)->toBeTrue()
        ->and($line->parentPreparation->owner_id)->toBe($line->componentPreparation->owner_id);
});

// --- visibility rules: units -----------------------------------------------------------

test('refuses a unit that weighs a different component than the line\'s', function () {
    $unitOnAnotherIngredient = UnitFactory::new()->create();
    $ingredient = IngredientFactory::new()->create();

    expect(fn () => RecipeLineFactory::new()->create([
        'ingredient_id' => $ingredient->id,
        'unit_id' => $unitOnAnotherIngredient->id,
    ]))->toThrow(LogicException::class);

    expect(RecipeLine::query()->count())->toBe(0);
});

test('refuses an ingredient unit on a line whose component is a preparation', function () {
    $owner = User::factory()->create();
    $product = recipeLineProductOwnedBy($owner);
    $component = recipeLinePreparationOwnedBy($owner);
    $ingredientUnit = UnitFactory::new()->create();

    expect(fn () => RecipeLineFactory::new()->forProduct($product)->create([
        'ingredient_id' => null,
        'component_preparation_id' => $component->id,
        'unit_id' => $ingredientUnit->id,
    ]))->toThrow(LogicException::class);
});

test("refuses another user's private unit, even on a public ingredient", function () {
    $stranger = User::factory()->create();
    $strangersUnit = UnitFactory::new()->ownedBy($stranger)->create();
    $product = recipeLineProductOwnedBy(User::factory()->create());

    expect(fn () => RecipeLineFactory::new()->forProduct($product)->withUnit($strangersUnit)->create())
        ->toThrow(LogicException::class);

    expect(RecipeLine::query()->count())->toBe(0);
});

test('accepts a public unit on the line\'s own ingredient', function () {
    $unit = UnitFactory::new()->create();

    $line = RecipeLineFactory::new()->withUnit($unit)->create();

    expect($line->exists)->toBeTrue()
        ->and($line->unit_id)->toBe($unit->id)
        ->and($line->ingredient_id)->toBe($unit->ingredient_id);
});

test('accepts the owner\'s own private unit on a public ingredient', function () {
    $owner = User::factory()->create();
    $unit = UnitFactory::new()->ownedBy($owner)->create();
    $product = recipeLineProductOwnedBy($owner);

    $line = RecipeLineFactory::new()->forProduct($product)->withUnit($unit)->create();

    expect($line->exists)->toBeTrue();
});

test('accepts the owner\'s preparation unit on a line using that preparation', function () {
    $owner = User::factory()->create();
    $component = recipeLinePreparationOwnedBy($owner);
    $unit = UnitFactory::new()->forPreparation($component)->create();
    $product = recipeLineProductOwnedBy($owner);

    $line = RecipeLineFactory::new()->forProduct($product)->withUnit($unit)->create();

    expect($line->exists)->toBeTrue()
        ->and($line->component_preparation_id)->toBe($component->id)
        ->and($line->unit_id)->toBe($unit->id);
});

// --- mass assignment -------------------------------------------------------------------

test('refuses to let mass assignment choose the recipe a line belongs to', function () {
    $product = ProductFactory::new()->create();

    $line = new RecipeLine([
        'product_id' => $product->id,
        'parent_preparation_id' => $product->id,
        'ingredient_id' => IngredientFactory::new()->create()->id,
        'position' => 1,
        'quantity' => 10,
    ]);

    expect($line->product_id)->toBeNull()
        ->and($line->parent_preparation_id)->toBeNull();
});

test('refuses to create a line pointed at a recipe through mass assignment alone', function () {
    $product = ProductFactory::new()->create();

    // product_id is dropped by mass assignment, so the line has no parent and the
    // one-parent CHECK rejects it. Only the exception is asserted (Postgres aborts the
    // transaction after a constraint error).
    expect(fn () => RecipeLine::query()->create([
        'product_id' => $product->id,
        'ingredient_id' => IngredientFactory::new()->create()->id,
        'position' => 1,
        'quantity' => 10,
    ]))->toThrow(QueryException::class, 'recipe_lines_one_parent');
});

test('creates a line through its recipe, ignoring any other parent passed in the data', function () {
    $owner = User::factory()->create();
    $product = recipeLineProductOwnedBy($owner);
    $otherRecipe = recipeLinePreparationOwnedBy($owner);

    $line = $product->recipeLines()->create([
        'parent_preparation_id' => $otherRecipe->id,
        'ingredient_id' => IngredientFactory::new()->create()->id,
        'position' => 1,
        'quantity' => 10,
    ]);

    $reloaded = $line->fresh();

    expect($reloaded->product_id)->toBe($product->id)
        ->and($reloaded->parent_preparation_id)->toBeNull();
});

test('refuses to let mass assignment move an existing line to another recipe', function () {
    $line = RecipeLineFactory::new()->create();
    $originalProductId = $line->product_id;
    $otherProduct = ProductFactory::new()->create();

    $line->fill(['product_id' => $otherProduct->id, 'quantity' => 3])->save();

    $reloaded = $line->fresh();

    expect($reloaded->product_id)->toBe($originalProductId)
        ->and($reloaded->quantity)->toBe('3.00');
});

// --- delete behavior -------------------------------------------------------------------

test('is deleted along with its product', function () {
    $product = ProductFactory::new()->create();
    RecipeLineFactory::new()->forProduct($product)->count(2)->create();
    $otherLine = RecipeLineFactory::new()->create();

    $product->delete();

    expect(RecipeLine::query()->pluck('id')->all())->toBe([$otherLine->id]);
});

test('is deleted along with the preparation it belongs to', function () {
    $preparation = PreparationFactory::new()->create();
    RecipeLineFactory::new()->forPreparation($preparation)->count(2)->create();
    $otherLine = RecipeLineFactory::new()->create();

    $preparation->delete();

    expect(RecipeLine::query()->pluck('id')->all())->toBe([$otherLine->id]);
});

test('refuses deleting a preparation still used in another recipe', function () {
    $line = RecipeLineFactory::new()->forProduct()->withComponentPreparation()->create();

    // Only the exception is asserted: Postgres aborts the transaction after the FK error.
    expect(fn () => $line->componentPreparation->delete())->toThrow(QueryException::class);
});

test('refuses deleting a public ingredient still used in a recipe', function () {
    $line = RecipeLineFactory::new()->create();

    expect(fn () => $line->ingredient->delete())->toThrow(QueryException::class);
});

test('refuses deleting a unit still used in a recipe', function () {
    $line = RecipeLineFactory::new()->withUnit()->create();

    expect(fn () => $line->unit->delete())->toThrow(QueryException::class);
});

// --- relations -------------------------------------------------------------------------

test('returns its product as its parent and its ingredient as its component', function () {
    $product = ProductFactory::new()->create();
    $ingredient = IngredientFactory::new()->create();
    $unit = UnitFactory::new()->create(['ingredient_id' => $ingredient->id]);

    $line = RecipeLineFactory::new()->forProduct($product)->withUnit($unit)->create();

    expect($line->parent())->toBeInstanceOf(Product::class)
        ->and($line->parent()->id)->toBe($product->id)
        ->and($line->component())->toBeInstanceOf(Ingredient::class)
        ->and($line->component()->id)->toBe($ingredient->id)
        ->and($line->unit)->toBeInstanceOf(Unit::class)
        ->and($line->unit->id)->toBe($unit->id);
});

test('returns its preparation as its parent and a preparation as its component', function () {
    $owner = User::factory()->create();
    $parent = recipeLinePreparationOwnedBy($owner);
    $component = recipeLinePreparationOwnedBy($owner);

    $line = RecipeLineFactory::new()->forPreparation($parent)->withComponentPreparation($component)->create();

    expect($line->parent())->toBeInstanceOf(Preparation::class)
        ->and($line->parent()->id)->toBe($parent->id)
        ->and($line->component())->toBeInstanceOf(Preparation::class)
        ->and($line->component()->id)->toBe($component->id)
        ->and($line->unit)->toBeNull();
});

test('reports a line without a parent or component as a logic error rather than returning null', function () {
    $line = new RecipeLine;

    expect(fn () => $line->parent())->toThrow(LogicException::class)
        ->and(fn () => $line->component())->toThrow(LogicException::class);
});
