<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Preparation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-support factory only. The model has no HasFactory trait (it has no production use
 * for one), so this factory is used directly as `UnitFactory::new()` rather than
 * `Unit::factory()`.
 *
 * Defaults to a public unit (owner_id null) on a public ingredient — the only combination
 * that is valid without knowing an owner (see Unit's saving() invariant).
 *
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /** @var class-string<Unit> */
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Factory instances on `<relation>_id` keys are resolved by Eloquent (see
            // IngredientFactory for details); IngredientFactory defaults to public.
            'ingredient_id' => IngredientFactory::new(),
            'preparation_id' => null,
            'format_id' => FormatFactory::new(),
            'owner_id' => null,
            // Strictly positive, matching the units_grams_positive CHECK constraint.
            'grams' => fake()->randomFloat(2, 1, 500),
        ];
    }

    /**
     * A private unit owned by the given user. On the default public ingredient this is
     * always valid; to put it on a private ingredient, pass that ingredient's owner here and
     * its id as `ingredient_id`.
     */
    public function ownedBy(User $owner): static
    {
        return $this->state(fn (): array => ['owner_id' => $owner->id]);
    }

    /**
     * A unit on the given preparation (a new one by default), owned by that preparation's
     * owner — the only valid owner, since preparations are always private.
     */
    public function forPreparation(?Preparation $preparation = null): static
    {
        return $this->state(function () use ($preparation): array {
            /** @var Preparation $component see IngredientFactory::private() for why the @var */
            $component = $preparation ?? PreparationFactory::new()->create();

            return [
                'ingredient_id' => null,
                'preparation_id' => $component->id,
                'owner_id' => $component->owner_id,
            ];
        });
    }
}
