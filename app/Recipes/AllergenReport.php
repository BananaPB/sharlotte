<?php

declare(strict_types=1);

namespace App\Recipes;

/**
 * Result of an allergen computation: sorted, deduplicated allergen codes.
 * `traces` never repeats a code already in `allergens`.
 */
final readonly class AllergenReport
{
    /**
     * @param  list<string>  $allergens  allergens contained
     * @param  list<string>  $traces  allergens that may be present as traces only
     */
    public function __construct(
        public array $allergens,
        public array $traces,
    ) {}
}
