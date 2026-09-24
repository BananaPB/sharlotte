<?php

declare(strict_types=1);

use App\Models\IngredientCategory;
use Database\Seeders\IngredientCategorySeeder;

// RefreshDatabase + TestCase are wired automatically for the Feature directory, see
// tests/Pest.php.

test('seeds exactly the 20 fixed ingredient categories', function () {
    $this->seed(IngredientCategorySeeder::class);

    expect(IngredientCategory::query()->count())->toBe(20);
});

test('seeds the orphans category with its real label and description', function () {
    // Locks in that the real replacement text landed instead of the source export's
    // leftover lorem-ipsum placeholder for this one category (see the seeder's comment).
    $this->seed(IngredientCategorySeeder::class);

    $orphans = IngredientCategory::query()->where('code', 'orphans')->first();

    expect($orphans)->not->toBeNull()
        ->and($orphans->label_fr)->toBe('Orphelins')
        ->and($orphans->description_fr)->toBe('Ingrédients qui ne correspondent à aucune autre catégorie.')
        ->and($orphans->description_fr)->not->toContain('Lorem ipsum');
});

test('is idempotent: re-running the seeder does not duplicate or fail', function () {
    $this->seed(IngredientCategorySeeder::class);
    $this->seed(IngredientCategorySeeder::class);

    expect(IngredientCategory::query()->count())->toBe(20);
});
