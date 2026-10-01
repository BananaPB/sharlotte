<?php

declare(strict_types=1);

use App\Enums\IngredientPrivacy;
use App\Enums\IngredientStorage;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\AllergenFactory;
use Database\Factories\IngredientCategoryFactory;
use Database\Factories\IngredientFactory;
use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Pest.php only binds Laravel's TestCase + RefreshDatabase to the Feature directory. These
// tests exercise a model against a real (Postgres) database, so they need the same binding
// here — done locally rather than widening Pest.php's global config for every Unit test.
uses(TestCase::class, RefreshDatabase::class);

/**
 * The seven core nutrition values are NOT NULL (docs/decisions.md entry 9), so any test
 * creating an ingredient by hand (rather than through IngredientFactory) must supply them.
 *
 * @return array<string, float|int>
 */
function ingredientCoreNutrition(): array
{
    return [
        'calories' => 52,
        'fats' => 0.17,
        'saturates' => 0.03,
        'carbohydrates' => 13.81,
        'sugars' => 10.39,
        'proteins' => 0.26,
        'salt' => 0,
    ];
}

// This is the actual regression test docs/decisions.md #5 exists to enable: prove nutrition
// decimals survive a real Postgres round-trip byte-exact, with no float drift. SQLite's type
// affinity would silently do float math on these columns instead of exact NUMERIC arithmetic.
test('nutrition decimal values round-trip through Postgres without float drift', function () {
    $category = IngredientCategoryFactory::new()->create();

    $ingredient = Ingredient::query()->create([
        'category_id' => $category->id,
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
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
        'name' => '0.1 plus 0.2 trap',
        'slug' => 'zero-one-plus-zero-two-trap-fresh',
        'storage' => IngredientStorage::Fresh,
        'fats' => 0.1 + 0.2,
    ]);

    expect((string) Ingredient::query()->findOrFail($ingredient->id)->fats)->toBe('0.30');
});

test('fibers and water are genuinely nullable, distinct from zero', function () {
    $category = IngredientCategoryFactory::new()->create();

    $unmeasured = Ingredient::query()->create([
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
        'name' => 'Unmeasured fibers and water',
        'slug' => 'unmeasured-fibers-and-water-fresh',
        'storage' => IngredientStorage::Fresh,
        'fibers' => null,
        'water' => null,
    ]);

    $measuredAtZero = Ingredient::query()->create([
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
        'name' => 'Measured at zero',
        'slug' => 'measured-at-zero-fresh',
        'storage' => IngredientStorage::Fresh,
        'fibers' => 0,
        'water' => 0,
    ]);

    $unmeasured = Ingredient::query()->findOrFail($unmeasured->id);
    $measuredAtZero = Ingredient::query()->findOrFail($measuredAtZero->id);

    expect($unmeasured->fibers)->toBeNull()
        ->and($unmeasured->water)->toBeNull()
        ->and($measuredAtZero->fibers)->toBe('0.00')
        ->and($measuredAtZero->water)->toBe('0.00');
});

test('refuses at the database level to save an ingredient without a core nutrition value', function (string $field) {
    $category = IngredientCategoryFactory::new()->create();

    expect(fn () => Ingredient::query()->create([
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
        'name' => "Missing {$field}",
        'slug' => "missing-{$field}-fresh",
        'storage' => IngredientStorage::Fresh,
        $field => null,
    ]))->toThrow(QueryException::class);
})->with(['calories', 'fats', 'saturates', 'carbohydrates', 'sugars', 'proteins', 'salt']);

test('stores calories as an exact two-decimal value rather than a rounded integer', function () {
    $ingredient = IngredientFactory::new()->create(['calories' => 72.8]);

    expect(Ingredient::query()->findOrFail($ingredient->id)->calories)->toBe('72.80');
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

    // Assigned explicitly: owner_id is not mass-assignable, so update([...]) would drop it.
    $ingredient->owner_id = $user->id;
    $ingredient->save();

    expect($ingredient->fresh()->privacy)->toBe(IngredientPrivacy::Private);
});

test('refuses to let mass assignment choose the owner on create', function () {
    $category = IngredientCategoryFactory::new()->create();
    $user = User::factory()->create();

    $ingredient = Ingredient::query()->create([
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
        'owner_id' => $user->id,
        'name' => 'Sneaky owner on create',
        'slug' => 'sneaky-owner-on-create-fresh',
        'storage' => IngredientStorage::Fresh,
    ]);

    $reloaded = $ingredient->fresh();

    expect($reloaded->owner_id)->toBeNull()
        ->and($reloaded->privacy)->toBe(IngredientPrivacy::Public);
});

test('refuses to let mass assignment change the owner of an existing ingredient', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $ingredient = IngredientFactory::new()->create(['owner_id' => $owner->id]);

    $ingredient->fill(['owner_id' => $stranger->id, 'name' => 'Renamed'])->save();

    $reloaded = $ingredient->fresh();

    expect($reloaded->owner_id)->toBe($owner->id)
        ->and($reloaded->name)->toBe('Renamed');
});

test('privacy ignores a manually-set value and derives from owner_id instead', function () {
    // `privacy` is deliberately absent from $fillable (mass-assigning it would defeat the
    // point of deriving it), so this sets it directly to prove the saving() hook — not just
    // the fillable guard — is what keeps it in lockstep with owner_id. See
    // Ingredient::booted()'s doc comment.
    $category = IngredientCategoryFactory::new()->create();

    $ingredient = new Ingredient([
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
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

test('has its units, public and private alike', function () {
    $user = User::factory()->create();
    $ingredient = IngredientFactory::new()->create();
    $public = UnitFactory::new()->create(['ingredient_id' => $ingredient->id]);
    $private = UnitFactory::new()->ownedBy($user)->create(['ingredient_id' => $ingredient->id]);
    UnitFactory::new()->create();

    expect($ingredient->units)->toHaveCount(2)
        ->each->toBeInstanceOf(Unit::class);
    expect($ingredient->units->pluck('id')->sort()->values()->all())
        ->toBe(collect([$public->id, $private->id])->sort()->values()->all());
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

test('defaults to_review to false and casts it to a real boolean', function () {
    $category = IngredientCategoryFactory::new()->create();

    // Deliberately omits to_review from the create() payload — this is what proves the
    // column's own DB-level default (not the factory's explicit `false`) is what applies.
    $ingredient = Ingredient::query()->create([
        ...ingredientCoreNutrition(),
        'category_id' => $category->id,
        'name' => 'Unflagged ingredient',
        'slug' => 'unflagged-ingredient-fresh',
        'storage' => IngredientStorage::Fresh,
    ]);

    $reloaded = Ingredient::query()->findOrFail($ingredient->id);

    expect($reloaded->to_review)->toBeFalse()
        ->and($reloaded->to_review)->toBeBool();
});

test('rejects a duplicate slug at the database level', function () {
    IngredientFactory::new()->create(['slug' => 'duplicate-slug']);

    expect(fn () => IngredientFactory::new()->create(['slug' => 'duplicate-slug']))
        ->toThrow(QueryException::class);
});
