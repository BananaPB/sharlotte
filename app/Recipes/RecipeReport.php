<?php

declare(strict_types=1);

namespace App\Recipes;

/**
 * Everything known about one recipe: its weight, nutrition, allergens and servings count.
 * `servings` is the product's servings, or null for a preparation (docs/decisions.md entry 14).
 */
final readonly class RecipeReport
{
    /**
     * @param  numeric-string  $weightGrams  4 decimals
     */
    public function __construct(
        public string $weightGrams,
        public NutritionFacts $nutrition,
        public AllergenReport $allergens,
        public ?int $servings,
    ) {}
}
