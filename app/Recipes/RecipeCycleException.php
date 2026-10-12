<?php

declare(strict_types=1);

namespace App\Recipes;

use LogicException;

/**
 * A recipe line would make a preparation contain itself, directly or through others.
 * A LogicException like the other recipe-line invariants: reaching it means a caller skipped
 * validation. Phase 3's forms can catch it and show a friendly message.
 */
class RecipeCycleException extends LogicException
{
    public static function forLine(string $parentName, int $parentId, string $componentName, int $componentId): self
    {
        return new self(sprintf(
            'Cannot use preparation "%s" (#%d) as a component of preparation "%s" (#%d): it would make "%s" contain itself.',
            $componentName,
            $componentId,
            $parentName,
            $parentId,
            $parentName,
        ));
    }
}
