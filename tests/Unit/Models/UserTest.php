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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Same local binding as IngredientTest.php: these tests need a real (Postgres) database.
uses(TestCase::class, RefreshDatabase::class);

/**
 * A user with a full private recipe tree, built so that every NO ACTION foreign key on
 * recipe_lines points at something the user owns and the database cascades away:
 *
 * - a private ingredient with a private unit, used (with that unit) in a preparation;
 * - that preparation has its own unit, and is used (with it) in a product and in a second
 *   preparation;
 * - the product also uses a public ingredient with the user's private unit on it, and a
 *   public ingredient with a public unit.
 *
 * @return array{user: User, publicIngredient: Ingredient, publicUnit: Unit}
 */
function userWithFullRecipeTree(): array
{
    /** @var Ingredient $privateIngredient */
    $privateIngredient = IngredientFactory::new()->private()->create();
    $user = User::query()->findOrFail($privateIngredient->owner_id);

    /** @var Ingredient $publicIngredient */
    $publicIngredient = IngredientFactory::new()->create();
    /** @var Unit $publicUnit */
    $publicUnit = UnitFactory::new()->create(['ingredient_id' => $publicIngredient->id]);
    /** @var Unit $privateUnitOnPublicIngredient */
    $privateUnitOnPublicIngredient = UnitFactory::new()->ownedBy($user)->create(['ingredient_id' => $publicIngredient->id]);
    /** @var Unit $privateIngredientUnit */
    $privateIngredientUnit = UnitFactory::new()->ownedBy($user)->create(['ingredient_id' => $privateIngredient->id]);

    /** @var Preparation $cream */
    $cream = PreparationFactory::new()->ownedBy($user)->create();
    /** @var Unit $creamUnit */
    $creamUnit = UnitFactory::new()->forPreparation($cream)->create();
    RecipeLineFactory::new()->forPreparation($cream)->withUnit($privateIngredientUnit)->create();
    RecipeLineFactory::new()->forPreparation($cream)->withIngredient($publicIngredient)->create();

    /** @var Preparation $filling */
    $filling = PreparationFactory::new()->ownedBy($user)->create();
    RecipeLineFactory::new()->forPreparation($filling)->withUnit($creamUnit)->create();

    /** @var Product $tart */
    $tart = ProductFactory::new()->ownedBy($user)->create();
    RecipeLineFactory::new()->forProduct($tart)->withUnit($creamUnit)->create();
    RecipeLineFactory::new()->forProduct($tart)->withComponentPreparation($filling)->create();
    RecipeLineFactory::new()->forProduct($tart)->withUnit($privateUnitOnPublicIngredient)->create();
    RecipeLineFactory::new()->forProduct($tart)->withUnit($publicUnit)->create();

    return ['user' => $user, 'publicIngredient' => $publicIngredient, 'publicUnit' => $publicUnit];
}

/**
 * @return array<string, list<int>> ids of every recipe-related row, per table
 */
function recipeTreeSnapshot(): array
{
    $ids = fn (string $model): array => $model::query()->orderBy('id')->pluck('id')->all();

    return [
        'ingredients' => $ids(Ingredient::class),
        'units' => $ids(Unit::class),
        'preparations' => $ids(Preparation::class),
        'products' => $ids(Product::class),
        'recipe_lines' => $ids(RecipeLine::class),
    ];
}

// --- account deletion ------------------------------------------------------------------

test('deletes a user along with all their recipes, units and private ingredients', function () {
    ['user' => $user] = userWithFullRecipeTree();

    $user->delete();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Preparation::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0)
        ->and(RecipeLine::query()->count())->toBe(0)
        ->and(Ingredient::query()->where('owner_id', $user->id)->count())->toBe(0)
        ->and(Unit::query()->where('owner_id', $user->id)->count())->toBe(0);
});

test('keeps the public data a deleted user was using', function () {
    ['user' => $user, 'publicIngredient' => $publicIngredient, 'publicUnit' => $publicUnit] = userWithFullRecipeTree();

    $user->delete();

    expect(Ingredient::query()->whereKey($publicIngredient->id)->exists())->toBeTrue()
        ->and(Unit::query()->whereKey($publicUnit->id)->exists())->toBeTrue();
});

test("leaves another user's recipes untouched when deleting a user", function () {
    ['user' => $user, 'publicIngredient' => $publicIngredient, 'publicUnit' => $publicUnit] = userWithFullRecipeTree();

    $neighbour = User::factory()->create();
    $neighbourPreparation = PreparationFactory::new()->ownedBy($neighbour)->create();
    $neighbourProduct = ProductFactory::new()->ownedBy($neighbour)->create();
    RecipeLineFactory::new()->forPreparation($neighbourPreparation)->withIngredient($publicIngredient)->create();
    RecipeLineFactory::new()->forProduct($neighbourProduct)->withUnit($publicUnit)->create();
    RecipeLineFactory::new()->forProduct($neighbourProduct)->withComponentPreparation($neighbourPreparation)->create();

    // Everything except the deleted user's own rows must survive.
    $user->delete();
    $after = recipeTreeSnapshot();

    expect($after['preparations'])->toBe([$neighbourPreparation->id])
        ->and($after['products'])->toBe([$neighbourProduct->id])
        ->and(RecipeLine::query()->count())->toBe(3)
        ->and($neighbourProduct->recipeLines()->count())->toBe(2)
        ->and($neighbourPreparation->recipeLines()->count())->toBe(1)
        ->and(User::query()->whereKey($neighbour->id)->exists())->toBeTrue();
});

test('deletes a user who has no recipes at all', function () {
    $user = User::factory()->create();

    expect($user->delete())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

test('rolls back the whole deletion, recipe lines included, when the account cannot be deleted', function () {
    ['user' => $user] = userWithFullRecipeTree();
    $privateIngredientId = Ingredient::query()->where('owner_id', $user->id)->value('id');

    // Corrupt data that RecipeLine's saving() invariants would refuse, inserted directly to
    // force the cascade to fail: another user's product using this user's private ingredient.
    $neighbourProduct = ProductFactory::new()->create();
    DB::table('recipe_lines')->insert([
        'product_id' => $neighbourProduct->id,
        'ingredient_id' => $privateIngredientId,
        'position' => 0,
        'quantity' => 1,
    ]);

    $before = recipeTreeSnapshot();

    // User::delete() wraps everything in a transaction; inside RefreshDatabase's own
    // transaction that becomes a savepoint, so the connection is usable again afterwards.
    expect(fn () => $user->delete())->toThrow(QueryException::class);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(recipeTreeSnapshot())->toBe($before);
});
