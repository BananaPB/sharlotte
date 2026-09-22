<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Storage state of an ingredient. Part of its identity, not a mutable attribute: a frozen
 * and a fresh version of "the same" food are different ingredients — different nutrition,
 * not interchangeable in a recipe. See docs/domain-model.md.
 */
enum IngredientStorage: string
{
    case Fresh = 'fresh';
    case Frozen = 'frozen';
    case Dry = 'dry';

    /**
     * Resolve the French label used in the source spreadsheet ("frais"/"surgelé"/"sec")
     * to its stable code. Returns null when unrecognized so the caller can report it
     * instead of guessing.
     */
    public static function fromFrenchLabel(string $label): ?self
    {
        return match (self::normalize($label)) {
            'frais' => self::Fresh,
            'surgele' => self::Frozen,
            'sec' => self::Dry,
            default => null,
        };
    }

    private static function normalize(string $value): string
    {
        return Str::of($value)->trim()->ascii()->lower()->toString();
    }
}
