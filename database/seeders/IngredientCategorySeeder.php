<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IngredientCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds all 20 fixed ingredient categories from the source spreadsheet (name + description).
 */
class IngredientCategorySeeder extends Seeder
{
    /**
     * @var list<array{code: string, label_fr: string, description_fr: string}>
     */
    private const CATEGORIES = [
        ['code' => 'fruits', 'label_fr' => 'Fruits', 'description_fr' => 'Fruits frais, congelés, en boîte. Crus comme cuits.'],
        ['code' => 'vegetables', 'label_fr' => 'Légumes', 'description_fr' => 'Légumes frais, congelés, en boîte. Crus comme cuits.'],
        ['code' => 'meats', 'label_fr' => 'Viandes', 'description_fr' => 'Viandes, oeufs, charcuteries et protéines animales.'],
        ['code' => 'fish', 'label_fr' => 'Poissons', 'description_fr' => 'Poissons, mollusques, crustacés et produits de la mer.'],
        ['code' => 'flours_seeds', 'label_fr' => 'Farines & graines', 'description_fr' => 'Farines, amidons, fécules et graines issues de céréales.'],
        ['code' => 'dairy', 'label_fr' => 'Produits laitiers & crèmerie', 'description_fr' => 'Laits, crèmes, fromages et dérivés.'],
        // Source label is "Fruits à coque é fruits secs" (likely a typo for "&", matching
        // the "&" separator used elsewhere in the source data), corrected here — confirm
        // with the user before this ships.
        ['code' => 'nuts_dried_fruits', 'label_fr' => 'Fruits à coque & fruits secs', 'description_fr' => 'Fruits séchés (abricot, raisin etc), fruits à coques (noix, arachides etc).'],
        ['code' => 'starches_legumes', 'label_fr' => 'Féculents & légumineuses', 'description_fr' => 'Féculents communs (riz, pâtes, semoules etc), tubercules et légumineuses.'],
        ['code' => 'spices_herbs_condiments', 'label_fr' => 'Épices, herbes & condiments', 'description_fr' => 'Épices en poudre ou non, herbes aromatiques fraîches comme surgelés et condiments divers comme les sauces.'],
        ['code' => 'fats_vinegars', 'label_fr' => 'Matières grasses & vinaigres', 'description_fr' => 'Matières grasses de toutes origines & vinaigres aromatisés ou non.'],
        ['code' => 'sweets', 'label_fr' => 'Sucreries', 'description_fr' => 'Sucres, confitures, coulis et autres préparations sucrées.'],
        ['code' => 'beverages', 'label_fr' => 'Boissons', 'description_fr' => 'Jus, nectars, alcools, eaux et autres boissons.'],
        ['code' => 'baking_cooking_aids', 'label_fr' => 'Aides à la cuisine, pâtisserie & boulangerie', 'description_fr' => 'Levures, poudres, décorations comestibles, pâtes préemballées etc.'],
        ['code' => 'meat_cheese_substitutes', 'label_fr' => 'Produits simili viandes & fromages', 'description_fr' => 'Produits végétaux, végétariens ou végétaliens en alternative aux viandes et fromages.'],
        ['code' => 'appetizers_biscuits', 'label_fr' => 'Apéritifs & biscuits', 'description_fr' => 'Biscuits salés, apéritifs etc.'],
        ['code' => 'savory_preparations', 'label_fr' => 'Appareils salés', 'description_fr' => 'Préparations de base, salées.'],
        ['code' => 'sweet_preparations', 'label_fr' => 'Appareils sucrés', 'description_fr' => 'Préparations de base, sucrées.'],
        ['code' => 'fermented_doughs', 'label_fr' => 'Pâtes fermentées', 'description_fr' => 'Préparations à base de levure pour pain et viennoiseries.'],
        ['code' => 'finished_products', 'label_fr' => 'Produits finis', 'description_fr' => 'Produits finis surgelés ou non, prêts à la vente.'],
        // Source export's description was leftover lorem-ipsum test data (not real content);
        // the text below is real, written to replace it — the one deliberate deviation from
        // "verbatim from source" in this seeder.
        ['code' => 'orphans', 'label_fr' => 'Orphelins', 'description_fr' => 'Ingrédients qui ne correspondent à aucune autre catégorie.'],
    ];

    /**
     * Run the database seeds.
     *
     * updateOrCreate on `code` so this is safe to re-run.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            IngredientCategory::query()->updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
