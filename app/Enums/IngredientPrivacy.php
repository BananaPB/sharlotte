<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Visibility of an ingredient: public (part of the shared CC-BY dataset, owner_id null)
 * or private (belongs to the user referenced by Ingredient::$owner_id). Deliberately
 * two-valued — no "family"/team-shared tier here, see docs/decisions.md
 * (deferred 2026-09-22): that belongs to the separate B2B SaaS repo, not this one.
 */
enum IngredientPrivacy: string
{
    case Public = 'public';
    case Private = 'private';
}
