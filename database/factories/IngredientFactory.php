<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngredientStorage;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-support factory only — public ingredient data is imported via
 * App\Console\Commands\ImportIngredientsCommand, not this factory. The model has no
 * HasFactory trait (it has no production use for one), so this factory is used directly as
 * `IngredientFactory::new()` rather than `Ingredient::factory()`.
 *
 * Nutrition values default to plain, round numbers — tests that specifically exercise
 * decimal-precision round-tripping (the point of docs/decisions.md #5) override these with
 * explicit float-drift-prone values rather than relying on whatever the factory randomizes.
 *
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /** @var class-string<Ingredient> */
    protected $model = Ingredient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words($nb, asText: true) always returns a string, but PHPStan types the return as
        // array|string since it can't narrow on a literal boolean argument. A (string) cast
        // would silence PHPStan's array|string=>string check but trips its separate
        // array-to-string cast warning instead, so narrow via @var here.
        /** @var string $name */
        $name = fake()->unique()->words(3, true);
        $storage = fake()->randomElement(IngredientStorage::cases());

        return [
            // Implicit relationship resolution: Eloquent factories resolve a Factory
            // instance assigned to a `<relation>_id`-shaped key by creating it and using its
            // key, matched against Ingredient::category()/owner() by convention — no
            // HasFactory trait required on either model for this to work.
            'category_id' => IngredientCategoryFactory::new(),
            'owner_id' => null,
            'name' => $name,
            'slug' => Ingredient::buildSlug($name, $storage, null),
            'storage' => $storage,
            'calories' => fake()->numberBetween(0, 900),
            'fats' => fake()->numberBetween(0, 100),
            'saturates' => fake()->numberBetween(0, 100),
            'carbohydrates' => fake()->numberBetween(0, 100),
            'sugars' => fake()->numberBetween(0, 100),
            'fibers' => fake()->numberBetween(0, 100),
            'proteins' => fake()->numberBetween(0, 100),
            'salt' => fake()->numberBetween(0, 100),
            'water' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * A private ingredient owned by a freshly-created user, with a slug consistent with that
     * ownership (see Ingredient::buildSlug()'s "-u{ownerId}" suffix).
     */
    public function private(): static
    {
        return $this->state(function (array $attributes): array {
            // Factory::create()'s general signature returns User|Collection<int, User> since
            // it can be called with a count; calling UserFactory::new() directly (rather than
            // User::factory(), see class docblock) means PHPStan's usual narrowing for the
            // conventional call pattern doesn't apply here, though a single instance is always
            // returned in practice since no count is passed.
            /** @var User $owner */
            $owner = UserFactory::new()->create();
            $storage = $attributes['storage'] ?? IngredientStorage::Fresh;
            // words($nb, asText: true) always returns a string, but PHPStan types the return as
            // array|string since it can't narrow on a literal boolean argument. A (string) cast
            // would silence PHPStan's array|string=>string check but trips its separate
            // array-to-string cast warning instead, so narrow via @var here.
            /** @var string $name */
            $name = $attributes['name'] ?? fake()->unique()->words(3, true);

            return [
                'owner_id' => $owner->id,
                'slug' => Ingredient::buildSlug($name, $storage, $owner->id),
            ];
        });
    }
}
