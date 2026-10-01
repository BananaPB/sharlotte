<?php

declare(strict_types=1);

use App\Models\Unit;
use Database\Factories\FormatFactory;
use Database\Factories\UnitFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Same local binding as IngredientTest.php: these tests need a real (Postgres) database.
uses(TestCase::class, RefreshDatabase::class);

test('has the units that give it a weight', function () {
    $format = FormatFactory::new()->create();
    $units = UnitFactory::new()->count(2)->create(['format_id' => $format->id]);
    UnitFactory::new()->create();

    expect($format->units)->toHaveCount(2)
        ->each->toBeInstanceOf(Unit::class);
    expect($format->units->pluck('id')->sort()->values()->all())
        ->toBe($units->pluck('id')->sort()->values()->all());
});

test('can be deleted when no unit uses it', function () {
    $format = FormatFactory::new()->create();

    $format->delete();

    expect($format->exists)->toBeFalse();
});

test('rejects a duplicate code at the database level', function () {
    FormatFactory::new()->create(['code' => 'slice']);

    // Only the exception is asserted: Postgres aborts the transaction after the error.
    expect(fn () => FormatFactory::new()->create(['code' => 'slice']))
        ->toThrow(QueryException::class);
});
