<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Preparation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-support factory only. The model has no HasFactory trait (it has no production use
 * for one), so this factory is used directly as `PreparationFactory::new()` rather than
 * `Preparation::factory()`.
 *
 * Defaults to a preparation owned by a freshly-created user (preparations are always owned,
 * docs/decisions.md entry 10).
 *
 * @extends Factory<Preparation>
 */
class PreparationFactory extends Factory
{
    /** @var class-string<Preparation> */
    protected $model = Preparation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words($nb, asText: true) always returns a string, but PHPStan types the return as
        // array|string, so narrow via @var (see IngredientCategoryFactory for why not a cast).
        /** @var string $name */
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            // Resolved by Eloquent into a newly-created user's id (see IngredientFactory).
            'owner_id' => UserFactory::new(),
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (): array => ['owner_id' => $owner->id]);
    }
}
