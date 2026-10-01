<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-support factory only. The model has no HasFactory trait (it has no production use
 * for one), so this factory is used directly as `RecipeLineFactory::new()` rather than
 * `RecipeLine::factory()`.
 *
 * Defaults to a product line using a public ingredient in grams — valid against
 * RecipeLine's saving() invariants without knowing any owner. Apply a parent state
 * (forPreparation()/forProduct()) before withComponentPreparation(), since the latter reads
 * the parent to pick a matching owner.
 *
 * @extends Factory<RecipeLine>
 */
class RecipeLineFactory extends Factory
{
    /** @var class-string<RecipeLine> */
    protected $model = RecipeLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Factory instances on `<column>_id` keys are resolved by Eloquent (see
            // IngredientFactory for details).
            'parent_preparation_id' => null,
            'product_id' => ProductFactory::new(),
            // IngredientFactory defaults to public, so any parent owner can use it.
            'ingredient_id' => IngredientFactory::new(),
            'component_preparation_id' => null,
            'unit_id' => null,
            'position' => fake()->numberBetween(0, 20),
            // Grams (no unit); strictly positive, matching recipe_lines_quantity_positive.
            'quantity' => fake()->randomFloat(2, 1, 500),
        ];
    }

    /**
     * A line in the given product's recipe (a new product by default).
     */
    public function forProduct(?Product $product = null): static
    {
        return $this->state(fn (): array => [
            'parent_preparation_id' => null,
            'product_id' => $product === null ? ProductFactory::new() : $product->id,
        ]);
    }

    /**
     * A line in the given preparation's recipe (a new preparation by default).
     */
    public function forPreparation(?Preparation $preparation = null): static
    {
        return $this->state(fn (): array => [
            'parent_preparation_id' => $preparation === null ? PreparationFactory::new() : $preparation->id,
            'product_id' => null,
        ]);
    }

    /**
     * A line using the given ingredient (a new public one by default) in grams. A private
     * ingredient must be owned by the parent's owner.
     */
    public function withIngredient(?Ingredient $ingredient = null): static
    {
        return $this->state(fn (): array => [
            'ingredient_id' => $ingredient === null ? IngredientFactory::new() : $ingredient->id,
            'component_preparation_id' => null,
            'unit_id' => null,
        ]);
    }

    /**
     * A line using a preparation as its component, in grams. By default a new preparation
     * owned by the parent's owner (resolving the parent now so both share it); a given
     * preparation must already be owned by the parent's owner.
     */
    public function withComponentPreparation(?Preparation $component = null): static
    {
        return $this->state(function (array $attributes) use ($component): array {
            if ($component !== null) {
                return [
                    'ingredient_id' => null,
                    'component_preparation_id' => $component->id,
                    'unit_id' => null,
                ];
            }

            [$parentKey, $parent] = $this->resolveParent($attributes);

            /** @var Preparation $newComponent see IngredientFactory::private() for why the @var */
            $newComponent = PreparationFactory::new()->create(['owner_id' => $parent->owner_id]);

            return [
                $parentKey => $parent->id,
                'ingredient_id' => null,
                'component_preparation_id' => $newComponent->id,
                'unit_id' => null,
            ];
        });
    }

    /**
     * A line counted in the given unit, on that unit's component. By default a new public
     * unit on a new public ingredient. A given unit must be visible to the parent's owner
     * (public, or theirs).
     */
    public function withUnit(?Unit $unit = null): static
    {
        return $this->state(function () use ($unit): array {
            /** @var Unit $lineUnit see IngredientFactory::private() for why the @var */
            $lineUnit = $unit ?? UnitFactory::new()->create();

            return [
                'ingredient_id' => $lineUnit->ingredient_id,
                'component_preparation_id' => $lineUnit->preparation_id,
                'unit_id' => $lineUnit->id,
                // A count of units, not grams.
                'quantity' => fake()->numberBetween(1, 5),
            ];
        });
    }

    /**
     * The line's parent as a saved model, creating it if it is still a pending factory.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: 'parent_preparation_id'|'product_id', 1: Preparation|Product}
     */
    private function resolveParent(array $attributes): array
    {
        $parentKey = ($attributes['parent_preparation_id'] ?? null) !== null ? 'parent_preparation_id' : 'product_id';
        $value = $attributes[$parentKey];

        if ($value instanceof Factory) {
            /** @var Preparation|Product $parent see IngredientFactory::private() for why the @var */
            $parent = $value->create();

            return [$parentKey, $parent];
        }

        // whereKey()->firstOrFail() rather than findOrFail(): $value is mixed, and findOrFail()
        // is typed to return a Collection when given an array.
        $parent = $parentKey === 'product_id'
            ? Product::query()->whereKey($value)->firstOrFail()
            : Preparation::query()->whereKey($value)->firstOrFail();

        return [$parentKey, $parent];
    }
}
