<?php

declare(strict_types=1);

use App\Enums\IngredientPrivacy;
use App\Enums\IngredientStorage;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\User;
use Database\Factories\AllergenFactory;
use Database\Factories\IngredientCategoryFactory;
use Database\Factories\IngredientFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Pest.php only binds Laravel's TestCase + RefreshDatabase to the Feature directory. These
// tests exercise a model against a real (Postgres) database, so they need the same binding
// here — done locally rather than widening Pest.php's global config for every Unit test.
uses(TestCase::class, RefreshDatabase::class);

// This is the actual regression test docs/decisions.md #5 exists to enable: prove nutrition
// decimals survive a real Postgres round-trip byte-exact, with no float drift. SQLite's type
// affinity would silently do float math on these columns instead of exact NUMERIC arithmetic.
test('nutrition decimal values round-trip through Postgres without float drift', function () {
    $category = IngredientCategoryFactory::new()->create();

    $ingredient = Ingredient::query()->create([
        'category_id' => $category->id,
        'owner_id' => null,
        'name' => 'Float trap ingredient',
        'slug' => 'float-trap-ingredient-fresh',
        'storage' => IngredientStorage::Fresh,
        'calories' => 123,
        'fats' => 12.34,
        'saturates' => 0.1,
        'carbohydrates' => 19.99,
        'sugars' => 0.29,
        'fibers' => 2.55,
        'proteins' => 8.07,
        'salt' => 0.01,
        'water' => 99.99,
    ]);

    // Reload from a fresh query rather than trusting the in-memory instance, so this
    // genuinely proves what is stored in the database, not just what Eloquent cast on the
    // way in.
    $reloaded = Ingredient::query()->findOrFail($ingredient->id);

    expect((string) $reloaded->fats)->toBe('12.34')
        ->and((string) $reloaded->saturates)->toBe('0.10')
        ->and((string) $reloaded->carbohydrates)->toBe('19.99')
        ->and((string) $reloaded->sugars)->toBe('0.29')
        ->and((string) $reloaded->fibers)->toBe('2.55')
        ->and((string) $reloaded->proteins)->toBe('8.07')
        ->and((string) $reloaded->salt)->toBe('0.01')
        ->and((string) $reloaded->water)->toBe('99.99');
});

test('a classic float-precision-loss value round-trips exactly', function () {
    $category = IngredientCategoryFactory::new()->create();

    // 0.1 + 0.2 is the textbook IEEE-754 binary float trap: it is 0.30000000000000004 in
    // double precision, not 0.3. If this column were float/double storage (as SQLite's type
    // affinity would silently substitute), that drift could surface here.
    $ingredient = Ingredient::query()->create([
        'category_id' => $category->id,
        'name' => '0.1 plus 0.2 trap',
        'slug' => 'zero-one-plus-zero-two-trap-fresh',
        'storage' => IngredientStorage::Fresh,
        'fats' => 0.1 + 0.2,
    ]);

    expect((string) Ingredient::query()->findOrFail($ingredient->id)->fats)->toBe('0.30');
});

test('nutrition fields are genuinely nullable, distinct from zero', function () {
    $category = IngredientCategoryFactory::new()->create();

    $ingredient = Ingredient::query()->create([
        'category_id' => $category->id,
        'name' => 'Unmeasured water content',
        'slug' => 'unmeasured-water-content-fresh',
        'storage' => IngredientStorage::Fresh,
        'water' => null,
        'salt' => 0,
    ]);

    $reloaded = Ingredient::query()->findOrFail($ingredient->id);

    expect($reloaded->water)->toBeNull()
        ->and((string) $reloaded->salt)->toBe('0.00');
});

test('derives public privacy when owner_id is null', function () {
    $ingredient = IngredientFactory::new()->create(['owner_id' => null]);

    expect($ingredient->fresh()->privacy)->toBe(IngredientPrivacy::Public);
});

test('derives private privacy when owner_id is set', function () {
    $user = User::factory()->create();

    $ingredient = IngredientFactory::new()->create(['owner_id' => $user->id]);

    expect($ingredient->fresh()->privacy)->toBe(IngredientPrivacy::Private);
});

test('privacy is recomputed, not just set once, when owner_id changes on an existing row', function () {
    $ingredient = IngredientFactory::new()->create(['owner_id' => null]);
    $user = User::factory()->create();

    $ingredient->update(['owner_id' => $user->id]);

    expect($ingredient->fresh()->privacy)->toBe(IngredientPrivacy::Private);
});

test('privacy ignores a manually-set value and derives from owner_id instead', function () {
    // `privacy` is deliberately absent from $fillable (mass-assigning it would defeat the
    // point of deriving it), so this sets it directly to prove the saving() hook — not just
    // the fillable guard — is what keeps it in lockstep with owner_id. See
    // Ingredient::booted()'s doc comment.
    $category = IngredientCategoryFactory::new()->create();

    $ingredient = new Ingredient([
        'category_id' => $category->id,
        'owner_id' => null,
        'name' => 'Manually flagged private',
        'slug' => 'manually-flagged-private-fresh',
        'storage' => IngredientStorage::Fresh,
    ]);
    $ingredient->privacy = IngredientPrivacy::Private;
    $ingredient->save();

    expect($ingredient->fresh()->privacy)->toBe(IngredientPrivacy::Public);
});

test('belongs to its category', function () {
    $category = IngredientCategoryFactory::new()->create();
    $ingredient = IngredientFactory::new()->create(['category_id' => $category->id]);

    expect($ingredient->category)->toBeInstanceOf(IngredientCategory::class)
        ->and($ingredient->category->id)->toBe($category->id);
});

test('belongs to its owner when private', function () {
    $user = User::factory()->create();
    $ingredient = IngredientFactory::new()->create(['owner_id' => $user->id]);

    expect($ingredient->owner)->toBeInstanceOf(User::class)
        ->and($ingredient->owner->id)->toBe($user->id);
});

test('has no owner when public', function () {
    $ingredient = IngredientFactory::new()->create(['owner_id' => null]);

    expect($ingredient->owner)->toBeNull();
});

test('tracks allergens it definitely contains, separately from ones it may only trace', function () {
    $ingredient = IngredientFactory::new()->create();
    $gluten = AllergenFactory::new()->create(['code' => 'gluten']);
    $milk = AllergenFactory::new()->create(['code' => 'milk']);

    $ingredient->allergens()->attach($gluten);
    $ingredient->allergenTraces()->attach($milk);

    $reloaded = $ingredient->fresh(['allergens', 'allergenTraces']);

    expect($reloaded->allergens->pluck('id')->all())->toBe([$gluten->id])
        ->and($reloaded->allergenTraces->pluck('id')->all())->toBe([$milk->id]);
});

test('buildSlug disambiguates the same name by storage state', function () {
    expect(Ingredient::buildSlug('Carotte', IngredientStorage::Fresh, null))->toBe('carotte-fresh')
        ->and(Ingredient::buildSlug('Carotte', IngredientStorage::Frozen, null))->toBe('carotte-frozen')
        ->and(Ingredient::buildSlug('Carotte', IngredientStorage::Ambient, null))->toBe('carotte-ambient');
});

test('buildSlug disambiguates a public ingredient from the same name owned privately', function () {
    expect(Ingredient::buildSlug('Carotte', IngredientStorage::Fresh, null))->toBe('carotte-fresh')
        ->and(Ingredient::buildSlug('Carotte', IngredientStorage::Fresh, 42))->toBe('carotte-fresh-u42');
});

test('rejects a duplicate slug at the database level', function () {
    IngredientFactory::new()->create(['slug' => 'duplicate-slug']);

    expect(fn () => IngredientFactory::new()->create(['slug' => 'duplicate-slug']))
        ->toThrow(QueryException::class);
});
