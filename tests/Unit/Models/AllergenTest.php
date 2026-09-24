<?php

declare(strict_types=1);

use Database\Factories\AllergenFactory;
use Database\Factories\IngredientFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// See tests/Unit/Models/IngredientTest.php for why this local binding is needed: Pest.php
// only wires Laravel's TestCase + RefreshDatabase into the Feature directory.
uses(TestCase::class, RefreshDatabase::class);

test('tracks ingredients that contain it, separately from ones with only traces', function () {
    $allergen = AllergenFactory::new()->create();
    $containing = IngredientFactory::new()->create();
    $tracing = IngredientFactory::new()->create();

    $containing->allergens()->attach($allergen);
    $tracing->allergenTraces()->attach($allergen);

    $reloaded = $allergen->fresh(['ingredients', 'ingredientTraces']);

    expect($reloaded->ingredients->pluck('id')->all())->toBe([$containing->id])
        ->and($reloaded->ingredientTraces->pluck('id')->all())->toBe([$tracing->id]);
});

test('enforces a unique code', function () {
    AllergenFactory::new()->create(['code' => 'gluten']);

    expect(fn () => AllergenFactory::new()->create(['code' => 'gluten']))
        ->toThrow(QueryException::class);
});
