<?php

declare(strict_types=1);

namespace App\Recipes;

/**
 * Aggregated nutrition of a recipe: for the whole recipe (`totals`) and per 100 g (`per100g`).
 *
 * Both arrays are keyed `calories`, `fats`, `saturates`, `carbohydrates`, `sugars`, `fibers`,
 * `proteins`, `salt`, `water`. Each value is a decimal string with 2 decimals, or null when unknown
 * (docs/decisions.md entry 9). `weightGrams` is the recipe's weight, with 4 decimals.
 */
final readonly class NutritionFacts
{
    /**
     * @param  numeric-string  $weightGrams
     * @param  array<string, numeric-string|null>  $totals
     * @param  array<string, numeric-string|null>  $per100g
     * @param  array<string, numeric-string|null>|null  $perServing  same keys, null when no servings count was given
     * @param  numeric-string|null  $servingWeightGrams  4 decimals, null when no servings count was given
     */
    public function __construct(
        public string $weightGrams,
        public array $totals,
        public array $per100g,
        public ?int $servings = null,
        public ?array $perServing = null,
        public ?string $servingWeightGrams = null,
    ) {}
}
