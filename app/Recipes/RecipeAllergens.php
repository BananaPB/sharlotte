<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Contracts\HasRecipe;
use Illuminate\Support\Facades\DB;

/**
 * Allergens and traces of a product or preparation: the union over every ingredient used in
 * its tree (docs/domain-model.md). Presence, not weight: an ingredient counts even if its
 * scaled grams round to 0.0000.
 *
 * Loading: two queries (allergens, traces) for all ingredient ids, whatever the tree's width.
 * It knows nothing about users, policies or HTTP.
 */
class RecipeAllergens
{
    public function compute(HasRecipe $recipe): AllergenReport
    {
        // Always a fresh flattener: it keeps per-call state and must never be shared.
        $ingredientIds = array_keys((new RecipeFlattener)->flatten($recipe));

        return $this->fromIngredientIds($ingredientIds);
    }

    /**
     * @param  array<int, int>  $ingredientIds
     */
    public function fromIngredientIds(array $ingredientIds): AllergenReport
    {
        if ($ingredientIds === []) {
            return new AllergenReport([], []);
        }

        $allergens = $this->codes('ingredient_allergen', $ingredientIds);
        $traces = array_values(array_diff($this->codes('ingredient_allergen_trace', $ingredientIds), $allergens));

        return new AllergenReport($allergens, $traces);
    }

    /**
     * Sorted, distinct allergen codes linked to the ingredients by the given pivot table.
     *
     * @param  'ingredient_allergen'|'ingredient_allergen_trace'  $pivot  fixed table names only, never user input
     * @param  array<int, int>  $ingredientIds
     * @return list<string>
     */
    private function codes(string $pivot, array $ingredientIds): array
    {
        $codes = DB::table($pivot)
            ->join('allergens', 'allergens.id', '=', $pivot.'.allergen_id')
            ->whereIn($pivot.'.ingredient_id', $ingredientIds)
            ->distinct()
            ->pluck('allergens.code')
            ->map(fn ($code): string => (string) $code)
            ->all();

        // Sorted in PHP (byte order), not with ORDER BY: the database's collation (e.g. en_US.UTF-8
        // sorts "alpha, beta, Zeta") could make the order differ between local, CI and production.
        sort($codes, SORT_STRING);

        return $codes;
    }
}
