<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Models\Preparation;
use App\Models\RecipeLine;

/**
 * Answers "would using preparation C as a component of preparation P create a cycle?".
 *
 * It loads every preparation-in-preparation link of P's owner in ONE query, then walks from C
 * down its components in PHP, with a visited set so that a cycle already present in the data
 * cannot make the walk loop. A cycle exists if P is reachable from C (or C is P).
 *
 * Products are never checked by callers: a product is never a component, so it cannot be
 * part of a cycle. Only the owner's own preparations are read, as preparations are private.
 *
 * Known limits (accepted, see the PR description):
 * - Two simultaneous saves (A into B and B into A) can both pass: each check sees the
 *   database before the other save is committed. No lock is taken.
 * - insert() and other bulk writes skip model events, so they skip this guard.
 */
class RecipeCycleGuard
{
    public function wouldCreateCycle(int $parentPreparationId, int $componentPreparationId): bool
    {
        // Direct self-reference: answered without touching the database.
        if ($parentPreparationId === $componentPreparationId) {
            return true;
        }

        $links = RecipeLine::query()
            ->toBase()
            ->join('preparations', 'preparations.id', '=', 'recipe_lines.parent_preparation_id')
            ->whereNotNull('recipe_lines.component_preparation_id')
            // Subquery (still one SQL statement) so P's owner needs no separate lookup.
            ->whereIn('preparations.owner_id', Preparation::query()->whereKey($parentPreparationId)->select('owner_id'))
            ->get(['recipe_lines.parent_preparation_id', 'recipe_lines.component_preparation_id']);

        // parent id => ids of the preparations it uses.
        /** @var array<int, list<int>> $componentsOf */
        $componentsOf = [];
        foreach ($links as $link) {
            $componentsOf[(int) $link->parent_preparation_id][] = (int) $link->component_preparation_id;
        }

        $visited = [];
        $toVisit = [$componentPreparationId];

        while ($toVisit !== []) {
            $current = array_pop($toVisit);

            if ($current === $parentPreparationId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            foreach ($componentsOf[$current] ?? [] as $next) {
                $toVisit[] = $next;
            }
        }

        return false;
    }
}
