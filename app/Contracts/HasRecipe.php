<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\RecipeLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anything that has a recipe (Preparation, Product). The calculation engine (Phase 2 step 4)
 * works on recipe lines, not on the parent entity, so it is written once against this
 * interface (docs/decisions.md entry 8). Deliberately limited to this one method.
 */
interface HasRecipe
{
    /**
     * The recipe's lines, ordered by position then id.
     *
     * `covariant Model` rather than `$this`: an interface is not a Model, so PHPStan rejects
     * `$this` as HasMany's declaring-model type here; implementers still declare `$this`.
     *
     * @return HasMany<RecipeLine, covariant Model>
     */
    public function recipeLines(): HasMany;
}
