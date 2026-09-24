<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;

/**
 * The 14 allergens whose declaration is mandatory under EU Regulation 1169/2011, Annex II.
 * `code` is the stable identity Phase 2's allergen union/deduplication logic compares on;
 * `label_fr` is French-only for now (translation deferred, see docs/decisions.md,
 * deferred 2026-09-22).
 */
class AllergenSeeder extends Seeder
{
    /**
     * @var list<array{code: string, label_fr: string}>
     */
    private const ALLERGENS = [
        ['code' => 'gluten', 'label_fr' => 'Gluten'],
        ['code' => 'crustaceans', 'label_fr' => 'Crustacés'],
        ['code' => 'eggs', 'label_fr' => 'Œufs'],
        ['code' => 'fish', 'label_fr' => 'Poissons'],
        ['code' => 'peanuts', 'label_fr' => 'Arachides'],
        ['code' => 'soy', 'label_fr' => 'Soja'],
        ['code' => 'milk', 'label_fr' => 'Lait'],
        ['code' => 'tree_nuts', 'label_fr' => 'Fruits à coque'],
        ['code' => 'celery', 'label_fr' => 'Céleri'],
        ['code' => 'mustard', 'label_fr' => 'Moutarde'],
        ['code' => 'sesame', 'label_fr' => 'Sésame'],
        ['code' => 'sulphites', 'label_fr' => 'Sulfites'],
        ['code' => 'lupin', 'label_fr' => 'Lupin'],
        ['code' => 'molluscs', 'label_fr' => 'Mollusques'],
    ];

    /**
     * Run the database seeds.
     *
     * updateOrCreate on `code` so this is safe to re-run (labels can be tweaked and
     * re-seeded without duplicating rows).
     */
    public function run(): void
    {
        foreach (self::ALLERGENS as $allergen) {
            Allergen::query()->updateOrCreate(['code' => $allergen['code']], $allergen);
        }
    }
}
