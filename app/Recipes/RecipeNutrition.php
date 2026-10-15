<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Contracts\HasRecipe;
use App\Models\Ingredient;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Turns a recipe into aggregated nutrition facts: totals for the whole recipe and values per
 * 100 g, from its raw ingredients (docs/domain-model.md; per-100 g basis in docs/decisions.md
 * entry 10; unknown fibers/water in entry 9).
 *
 * Loading: one query for every ingredient used, selecting only the id and the nine nutrition
 * columns, so the query count never depends on the width of the tree.
 *
 * Arithmetic: BCMath on strings only, scale 8 for intermediate values, rounded half-up to 2
 * decimals at the end (non-negative amounts assumed, as in RecipeFlattener). No floats.
 *
 * It reads whatever the recipe points at: users, visibility and policies are not its concern.
 */
class RecipeNutrition
{
    /** The nine values, in the order of the result arrays. */
    private const NUTRIENTS = [
        'calories', 'fats', 'saturates', 'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water',
    ];

    /** The only two values that may be unknown: any unknown leaf makes the whole result unknown. */
    private const OPTIONAL_NUTRIENTS = ['fibers', 'water'];

    private const WORKING_SCALE = 8;

    private const RESULT_SCALE = 2;

    /** Half of the last result decimal, added before truncating to round half-up. */
    private const HALF_UP = '0.005';

    public function compute(HasRecipe $recipe, ?int $servings = null): NutritionFacts
    {
        // Fail fast: no query should run for servings that fromGrams() would refuse anyway.
        $this->assertValidServings($servings);

        // A fresh flattener each time: it keeps per-call state and must never be shared.
        return $this->fromGrams((new RecipeFlattener)->flatten($recipe), $servings);
    }

    /**
     * @param  array<int, numeric-string>  $gramsByIngredientId  ingredient id => grams, as returned by RecipeFlattener
     * @param  int|null  $servings  when given (>= 1), per-serving values are added to the result
     */
    public function fromGrams(array $gramsByIngredientId, ?int $servings = null): NutritionFacts
    {
        $this->assertValidServings($servings);

        $ingredients = $this->loadIngredients(array_keys($gramsByIngredientId));

        $weight = '0';
        /** @var array<string, numeric-string> $sums unrounded totals, scale 8 */
        $sums = array_fill_keys(self::NUTRIENTS, bcadd('0', '0', self::WORKING_SCALE));
        $unknown = [];

        foreach ($gramsByIngredientId as $ingredientId => $grams) {
            $ingredient = $ingredients[$ingredientId];
            $weight = bcadd($weight, $grams, self::WORKING_SCALE);

            // Counts as used even when its grams round to 0.0000.
            foreach (self::NUTRIENTS as $nutrient) {
                $value = $ingredient->getAttribute($nutrient);

                if ($value === null) {
                    $unknown[$nutrient] = true;

                    continue;
                }

                $contribution = bcdiv(bcmul($grams, $this->numeric($value), self::WORKING_SCALE), '100', self::WORKING_SCALE);
                $sums[$nutrient] = bcadd($sums[$nutrient], $contribution, self::WORKING_SCALE);
            }
        }

        $hasWeight = bccomp($weight, '0', self::WORKING_SCALE) > 0;
        $totals = [];
        $per100g = [];
        $perServing = [];

        foreach (self::NUTRIENTS as $nutrient) {
            if (isset($unknown[$nutrient]) && in_array($nutrient, self::OPTIONAL_NUTRIENTS, true)) {
                $totals[$nutrient] = null;
                $per100g[$nutrient] = null;
                $perServing[$nutrient] = null;

                continue;
            }

            $totals[$nutrient] = $this->round($sums[$nutrient]);
            // Nothing to divide by (empty recipe, or every quantity rounded to zero): unknown per 100 g.
            // Per 100 g comes from the unrounded sum, multiplying before dividing for a single rounding step.
            $per100g[$nutrient] = $hasWeight
                ? $this->round(bcdiv(bcmul($sums[$nutrient], '100', self::WORKING_SCALE), $weight, self::WORKING_SCALE))
                : null;

            // Per serving: the unrounded sum divided by servings, rounded once (never from the rounded total).
            if ($servings !== null) {
                $perServing[$nutrient] = $this->round(bcdiv($sums[$nutrient], (string) $servings, self::WORKING_SCALE));
            }
        }

        // bcadd truncates (it does not round) to 4 decimals: the weight is already the flattener's
        // 4-decimal sum, so this only normalises the format, and truncation is deliberate.
        return new NutritionFacts(
            bcadd($weight, '0', 4),
            $totals,
            $per100g,
            $servings,
            $servings === null ? null : $perServing,
            $servings === null ? null : $this->roundWeight(bcdiv($weight, (string) $servings, self::WORKING_SCALE)),
        );
    }

    private function assertValidServings(?int $servings): void
    {
        if ($servings !== null && $servings < 1) {
            throw new InvalidArgumentException('Servings must be at least 1, got '.$servings.'.');
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Ingredient>
     */
    private function loadIngredients(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $found = Ingredient::query()
            ->whereIn('id', $ids)
            ->get(['id', ...self::NUTRIENTS])
            ->keyBy('id')
            ->all();

        foreach ($ids as $id) {
            if (! isset($found[$id])) {
                throw new RuntimeException('Cannot compute nutrition: no ingredient with id '.$id.'.');
            }
        }

        return $found;
    }

    /**
     * Half-up to 4 decimals, for the weight of one serving. Same trick as HALF_UP: bcadd truncates
     * (never rounds) to 4 decimals, so adding half of the last decimal (0.00005) first makes it round half-up.
     *
     * @param  numeric-string  $value  scale 8
     * @return numeric-string
     */
    private function roundWeight(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', self::WORKING_SCALE), '0', 4);
    }

    /**
     * @param  numeric-string  $value  scale 8
     * @return numeric-string
     */
    private function round(string $value): string
    {
        return bcadd(bcadd($value, self::HALF_UP, self::WORKING_SCALE), '0', self::RESULT_SCALE);
    }

    /**
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        $text = (string) $value;

        if (! is_numeric($text) || preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw new UnexpectedValueException('Ingredient data contains a non-numeric nutrition value: "'.$text.'".');
        }

        return $text;
    }
}
