<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Contracts\HasRecipe;
use App\Models\Product;

/**
 * One call for everything about a recipe: the tree is flattened once, then the same result feeds
 * the nutrition and allergen computations, so the query count does not double.
 *
 * It knows nothing about users, policies or HTTP.
 */
class RecipeReporter
{
    public function compute(HasRecipe $recipe): RecipeReport
    {
        // Fresh helpers on every call: the flattener keeps per-call state and must never be shared.
        $gramsByIngredientId = (new RecipeFlattener)->flatten($recipe);

        // Only a product has servings; a preparation has none (docs/decisions.md entry 14).
        $servings = $recipe instanceof Product ? $recipe->servings : null;

        $nutrition = (new RecipeNutrition)->fromGrams($gramsByIngredientId, $servings);
        $allergens = (new RecipeAllergens)->fromIngredientIds(array_keys($gramsByIngredientId));

        return new RecipeReport($nutrition->weightGrams, $nutrition, $allergens, $servings);
    }
}
