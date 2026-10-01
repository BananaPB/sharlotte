<?php

declare(strict_types=1);

use App\Models\Format;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\FormatFactory;
use Database\Factories\IngredientFactory;
use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Same local binding as IngredientTest.php: these tests need a real (Postgres) database.
uses(TestCase::class, RefreshDatabase::class);

/**
 * A private ingredient together with its owner, since most ownership tests need both.
 *
 * @return array{0: Ingredient, 1: User}
 */
function privateIngredientWithOwner(): array
{
    /** @var Ingredient $ingredient */
    $ingredient = IngredientFactory::new()->private()->create();

    return [$ingredient, User::query()->findOrFail($ingredient->owner_id)];
}

/**
 * @param  iterable<int>  $ids
 * @return list<int>
 */
function sortedUnitIds(iterable $ids): array
{
    return collect($ids)->sort()->values()->all();
}

// --- grams storage ---------------------------------------------------------------------

test('stores grams as an exact two-decimal value', function () {
    $unit = UnitFactory::new()->create(['grams' => 40.1]);

    expect(Unit::query()->findOrFail($unit->id)->grams)->toBe('40.10');
});

test('refuses at the database level a unit weighing zero grams', function () {
    // Postgres aborts the transaction after a constraint error, so only the exception is
    // asserted here, with no follow-up query.
    expect(fn () => UnitFactory::new()->create(['grams' => 0]))
        ->toThrow(QueryException::class, 'units_grams_positive');
});

test('refuses at the database level a unit with a negative weight', function () {
    expect(fn () => UnitFactory::new()->create(['grams' => -5]))
        ->toThrow(QueryException::class, 'units_grams_positive');
});

// --- ownership rule --------------------------------------------------------------------

test('refuses a public unit on a private ingredient', function () {
    [$ingredient] = privateIngredientWithOwner();

    expect(fn () => UnitFactory::new()->create(['ingredient_id' => $ingredient->id]))
        ->toThrow(LogicException::class);

    expect(Unit::query()->count())->toBe(0);
});

test("refuses another user's unit on a private ingredient", function () {
    [$ingredient] = privateIngredientWithOwner();
    $stranger = User::factory()->create();

    expect(fn () => UnitFactory::new()->ownedBy($stranger)->create(['ingredient_id' => $ingredient->id]))
        ->toThrow(LogicException::class);

    expect(Unit::query()->count())->toBe(0);
});

test("accepts the owner's own unit on their private ingredient", function () {
    [$ingredient, $owner] = privateIngredientWithOwner();

    $unit = UnitFactory::new()->ownedBy($owner)->create(['ingredient_id' => $ingredient->id]);

    expect($unit->exists)->toBeTrue()
        ->and($unit->owner_id)->toBe($owner->id);
});

test("accepts the owner's unit even when owner_id arrives as a numeric string", function () {
    [$ingredient, $owner] = privateIngredientWithOwner();

    $unit = UnitFactory::new()->create([
        'ingredient_id' => $ingredient->id,
        'owner_id' => (string) $owner->id,
    ]);

    expect($unit->exists)->toBeTrue();
});

test('accepts a private unit on a public ingredient', function () {
    $user = User::factory()->create();
    $ingredient = IngredientFactory::new()->create(['owner_id' => null]);

    $unit = UnitFactory::new()->ownedBy($user)->create(['ingredient_id' => $ingredient->id]);

    expect($unit->exists)->toBeTrue();
});

test('accepts a public unit on a public ingredient', function () {
    $unit = UnitFactory::new()->create();

    expect($unit->exists)->toBeTrue()
        ->and($unit->owner_id)->toBeNull();
});

test("refuses making an owner's unit public when its ingredient is private", function () {
    [$ingredient, $owner] = privateIngredientWithOwner();
    $unit = UnitFactory::new()->ownedBy($owner)->create(['ingredient_id' => $ingredient->id]);

    $unit->owner_id = null;

    expect(fn () => $unit->save())->toThrow(LogicException::class);
    expect(Unit::query()->findOrFail($unit->id)->owner_id)->toBe($owner->id);
});

test('refuses moving a public unit onto a private ingredient', function () {
    $unit = UnitFactory::new()->create();
    [$privateIngredient] = privateIngredientWithOwner();

    $unit->ingredient_id = $privateIngredient->id;

    expect(fn () => $unit->save())->toThrow(LogicException::class);
});

test('refuses to let mass assignment choose the owner on create', function () {
    $user = User::factory()->create();
    $ingredient = IngredientFactory::new()->create(['owner_id' => null]);
    $format = FormatFactory::new()->create();

    $unit = Unit::query()->create([
        'ingredient_id' => $ingredient->id,
        'format_id' => $format->id,
        'owner_id' => $user->id,
        'grams' => 40,
    ]);

    expect($unit->fresh()->owner_id)->toBeNull();
});

test('refuses to let mass assignment change the owner of an existing unit', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $unit = UnitFactory::new()->ownedBy($owner)->create();

    $unit->fill(['owner_id' => $stranger->id, 'grams' => 55])->save();

    $reloaded = $unit->fresh();

    expect($reloaded->owner_id)->toBe($owner->id)
        ->and($reloaded->grams)->toBe('55.00');
});

// --- privacy and visibility ------------------------------------------------------------

test('is public when it has no owner', function () {
    expect(UnitFactory::new()->create()->isPublic())->toBeTrue();
});

test('is private when it has an owner', function () {
    $user = User::factory()->create();

    expect(UnitFactory::new()->ownedBy($user)->create()->isPublic())->toBeFalse();
});

test("shows a user the public units and their own, never another user's private ones", function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $public = UnitFactory::new()->create();
    $own = UnitFactory::new()->ownedBy($user)->create();
    UnitFactory::new()->ownedBy($stranger)->create();

    expect(sortedUnitIds(Unit::query()->visibleTo($user)->pluck('id')))
        ->toBe(sortedUnitIds([$public->id, $own->id]));
});

test("keeps hiding other users' private units when visibility is combined with another filter", function () {
    // Guards against the OR in visibleTo() leaking out of its group: without the wrapping
    // closure, `ingredient_id = ? AND owner_id IS NULL OR owner_id = ?` would return the
    // user's units on every ingredient.
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $ingredient = IngredientFactory::new()->create();
    $otherIngredient = IngredientFactory::new()->create();

    $public = UnitFactory::new()->create(['ingredient_id' => $ingredient->id]);
    $own = UnitFactory::new()->ownedBy($user)->create(['ingredient_id' => $ingredient->id]);
    UnitFactory::new()->ownedBy($stranger)->create(['ingredient_id' => $ingredient->id]);
    UnitFactory::new()->create(['ingredient_id' => $otherIngredient->id]);
    UnitFactory::new()->ownedBy($user)->create(['ingredient_id' => $otherIngredient->id]);

    $expected = sortedUnitIds([$public->id, $own->id]);

    expect(sortedUnitIds(Unit::query()->where('ingredient_id', $ingredient->id)->visibleTo($user)->pluck('id')))
        ->toBe($expected);

    // Same result whatever order the filters are chained in.
    expect(sortedUnitIds(Unit::query()->visibleTo($user)->where('ingredient_id', $ingredient->id)->pluck('id')))
        ->toBe($expected);
});

// --- delete behavior -------------------------------------------------------------------

test('is deleted along with its ingredient', function () {
    $ingredient = IngredientFactory::new()->create();
    UnitFactory::new()->count(2)->create(['ingredient_id' => $ingredient->id]);
    $otherUnit = UnitFactory::new()->create();

    $ingredient->delete();

    expect(Unit::query()->pluck('id')->all())->toBe([$otherUnit->id]);
});

test('a private unit is deleted along with its owner', function () {
    $user = User::factory()->create();
    UnitFactory::new()->ownedBy($user)->create();
    $publicUnit = UnitFactory::new()->create();

    $user->delete();

    expect(Unit::query()->pluck('id')->all())->toBe([$publicUnit->id]);
});

test('refuses deleting a format still used by a unit', function () {
    $unit = UnitFactory::new()->create();

    // Only the exception is asserted: Postgres aborts the transaction after the FK error.
    expect(fn () => $unit->format->delete())->toThrow(QueryException::class);
});

// --- relations -------------------------------------------------------------------------

test('belongs to its ingredient, format, and owner', function () {
    $user = User::factory()->create();
    $ingredient = IngredientFactory::new()->create();
    $format = FormatFactory::new()->create();

    $unit = UnitFactory::new()->ownedBy($user)->create([
        'ingredient_id' => $ingredient->id,
        'format_id' => $format->id,
    ]);

    expect($unit->ingredient)->toBeInstanceOf(Ingredient::class)
        ->and($unit->ingredient->id)->toBe($ingredient->id)
        ->and($unit->format)->toBeInstanceOf(Format::class)
        ->and($unit->format->id)->toBe($format->id)
        ->and($unit->owner)->toBeInstanceOf(User::class)
        ->and($unit->owner->id)->toBe($user->id);
});

test('has no owner when public', function () {
    expect(UnitFactory::new()->create()->owner)->toBeNull();
});
