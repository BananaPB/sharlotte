<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IngredientCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds only the category names explicitly confirmed so far. The source spreadsheet has
 * 20 fixed categories in total; the remaining ~13 codes/labels have not been handed over
 * yet and must be confirmed before this ships to production — see the Dev agent's summary
 * for this feature.
 */
class IngredientCategorySeeder extends Seeder
{
    /**
     * @var list<array{code: string, label_fr: string}>
     */
    private const CATEGORIES = [
        ['code' => 'fruits', 'label_fr' => 'Fruits'],
        ['code' => 'vegetables', 'label_fr' => 'Légumes'],
        ['code' => 'meats', 'label_fr' => 'Viandes'],
        ['code' => 'fish', 'label_fr' => 'Poissons'],
        ['code' => 'flours_seeds', 'label_fr' => 'Farines & graines'],
        ['code' => 'dairy', 'label_fr' => 'Produits laitiers & crèmerie'],
        // Source label is "Fruits à coque é fruits secs" (likely a typo for "&", matching
        // the "&" separator used elsewhere in the source data), corrected here — confirm
        // with the user before this ships.
        ['code' => 'nuts_dried_fruits', 'label_fr' => 'Fruits à coque & fruits secs'],
    ];

    /**
     * Run the database seeds.
     *
     * updateOrCreate on `code` so this is safe to re-run as the remaining categories are
     * confirmed and appended.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            IngredientCategory::query()->updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
