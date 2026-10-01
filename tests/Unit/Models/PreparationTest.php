<?php

declare(strict_types=1);

use App\Contracts\HasRecipe;
use App\Models\Preparation;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\PreparationFactory;
use Database\Factories\RecipeLineFactory;
use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Same local binding as IngredientTest.php: these tests need a real (Postgres) database.
uses(TestCase::class, RefreshDatabase::class);

// --- ownership -------------------------------------------------------------------------

test('refuses to let mass assignment choose the owner', function () {
    $user = User::factory()->create();

    $preparation = new Preparation(['name' => 'Crème pâtissière', 'owner_id' => $user->id]);

    expect($preparation->name)->toBe('Crème pâtissière')
        ->and($preparation->owner_id)->toBeNull();
});

test('refuses at the database level a preparation created without an explicit owner', function () {
    // owner_id is dropped by mass assignment, so the NOT NULL column rejects the row. Only
    // the exception is asserted: Postgres aborts the transaction after a constraint error.
    expect(fn () => Preparation::query()->create(['name' => 'Ganache', 'owner_id' => User::factory()->create()->id]))
        ->toThrow(QueryException::class);
});

test('refuses to let mass assignment change the owner of an existing preparation', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $preparation = PreparationFactory::new()->ownedBy($owner)->create();

    $preparation->fill(['owner_id' => $stranger->id, 'name' => 'Pâte sablée'])->save();

    $reloaded = $preparation->fresh();

    expect($reloaded->owner_id)->toBe($owner->id)
        ->and($reloaded->name)->toBe('Pâte sablée');
});

test('is created for an owner by associating them explicitly', function () {
    $owner = User::factory()->create();

    $preparation = new Preparation(['name' => 'Ganache']);
    $preparation->owner()->associate($owner);
    $preparation->save();

    expect($preparation->fresh()->owner_id)->toBe($owner->id);
});

// --- delete behavior -------------------------------------------------------------------

test('is deleted along with its owner', function () {
    $owner = User::factory()->create();
    PreparationFactory::new()->ownedBy($owner)->count(2)->create();
    $otherPreparation = PreparationFactory::new()->create();

    $owner->delete();

    expect(Preparation::query()->pluck('id')->all())->toBe([$otherPreparation->id]);
});

test('takes its units with it when deleted', function () {
    $preparation = PreparationFactory::new()->create();
    UnitFactory::new()->forPreparation($preparation)->count(2)->create();
    $otherUnit = UnitFactory::new()->create();

    $preparation->delete();

    expect(Unit::query()->pluck('id')->all())->toBe([$otherUnit->id]);
});

// --- relations -------------------------------------------------------------------------

test('has a recipe', function () {
    expect(PreparationFactory::new()->create())->toBeInstanceOf(HasRecipe::class);
});

test('belongs to its owner', function () {
    $owner = User::factory()->create();

    $preparation = PreparationFactory::new()->ownedBy($owner)->create();

    expect($preparation->owner)->toBeInstanceOf(User::class)
        ->and($preparation->owner->id)->toBe($owner->id);
});

test('separates its own recipe lines from the lines of other recipes that use it', function () {
    $owner = User::factory()->create();
    $preparation = PreparationFactory::new()->ownedBy($owner)->create();
    $tart = PreparationFactory::new()->ownedBy($owner)->create();

    $ownLine = RecipeLineFactory::new()->forPreparation($preparation)->create();
    $usage = RecipeLineFactory::new()->forPreparation($tart)->withComponentPreparation($preparation)->create();

    expect($preparation->recipeLines->pluck('id')->all())->toBe([$ownLine->id])
        ->and($preparation->usedInLines->pluck('id')->all())->toBe([$usage->id])
        ->and($tart->recipeLines->pluck('id')->all())->toBe([$usage->id])
        ->and($tart->usedInLines)->toBeEmpty();
});

test('has its own units, not those of other components', function () {
    $preparation = PreparationFactory::new()->create();
    $units = UnitFactory::new()->forPreparation($preparation)->count(2)->create();
    UnitFactory::new()->forPreparation()->create();
    UnitFactory::new()->create();

    expect($preparation->units->pluck('id')->sort()->values()->all())
        ->toBe($units->pluck('id')->sort()->values()->all());
});

test('has no recipe lines, usages, nor units when new', function () {
    $preparation = PreparationFactory::new()->create();

    expect($preparation->recipeLines)->toBeEmpty()
        ->and($preparation->usedInLines)->toBeEmpty()
        ->and($preparation->units)->toBeEmpty();
});
