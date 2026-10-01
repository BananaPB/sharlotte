<?php

declare(strict_types=1);

use App\Contracts\HasRecipe;
use App\Models\Product;
use App\Models\User;
use Database\Factories\ProductFactory;
use Database\Factories\RecipeLineFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Same local binding as IngredientTest.php: these tests need a real (Postgres) database.
uses(TestCase::class, RefreshDatabase::class);

// --- ownership -------------------------------------------------------------------------

test('refuses to let mass assignment choose the owner', function () {
    $user = User::factory()->create();

    $product = new Product(['name' => 'Tarte aux fraises', 'owner_id' => $user->id]);

    expect($product->name)->toBe('Tarte aux fraises')
        ->and($product->owner_id)->toBeNull();
});

test('refuses at the database level a product created without an explicit owner', function () {
    // owner_id is dropped by mass assignment, so the NOT NULL column rejects the row. Only
    // the exception is asserted: Postgres aborts the transaction after a constraint error.
    expect(fn () => Product::query()->create(['name' => 'Éclair', 'owner_id' => User::factory()->create()->id]))
        ->toThrow(QueryException::class);
});

test('refuses to let mass assignment change the owner of an existing product', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $product = ProductFactory::new()->ownedBy($owner)->create();

    $product->fill(['owner_id' => $stranger->id, 'name' => 'Paris-Brest'])->save();

    $reloaded = $product->fresh();

    expect($reloaded->owner_id)->toBe($owner->id)
        ->and($reloaded->name)->toBe('Paris-Brest');
});

// --- delete behavior -------------------------------------------------------------------

test('is deleted along with its owner', function () {
    $owner = User::factory()->create();
    ProductFactory::new()->ownedBy($owner)->count(2)->create();
    $otherProduct = ProductFactory::new()->create();

    $owner->delete();

    expect(Product::query()->pluck('id')->all())->toBe([$otherProduct->id]);
});

// --- relations -------------------------------------------------------------------------

test('has a recipe', function () {
    expect(ProductFactory::new()->create())->toBeInstanceOf(HasRecipe::class);
});

test('belongs to its owner', function () {
    $owner = User::factory()->create();

    $product = ProductFactory::new()->ownedBy($owner)->create();

    expect($product->owner)->toBeInstanceOf(User::class)
        ->and($product->owner->id)->toBe($owner->id);
});

test('has only its own recipe lines', function () {
    $product = ProductFactory::new()->create();
    $first = RecipeLineFactory::new()->forProduct($product)->create(['position' => 0]);
    $second = RecipeLineFactory::new()->forProduct($product)->create(['position' => 1]);
    RecipeLineFactory::new()->create();

    expect($product->recipeLines->pluck('id')->all())->toBe([$first->id, $second->id]);
});
