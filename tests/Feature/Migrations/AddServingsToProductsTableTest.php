<?php

declare(strict_types=1);

use Database\Factories\ProductFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Postgres DDL is transactional, so running down()/up() here is rolled back by
// RefreshDatabase along with everything else and never leaks into other tests.
function addServingsToProductsMigration(): object
{
    return require database_path('migrations/2026_10_14_090001_add_servings_to_products_table.php');
}

test('gives a product created without a servings value a servings count of 1', function () {
    $productId = DB::table('products')->insertGetId([
        'name' => 'Tarte',
        'owner_id' => ProductFactory::new()->create()->owner_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('products')->where('id', $productId)->value('servings'))->toBe(1);
});

test('accepts a servings count of 1 or more', function () {
    $one = ProductFactory::new()->create(['servings' => 1]);
    $eight = ProductFactory::new()->create(['servings' => 8]);

    expect(DB::table('products')->where('id', $one->id)->value('servings'))->toBe(1)
        ->and(DB::table('products')->where('id', $eight->id)->value('servings'))->toBe(8);
});

test('refuses a servings count of 0', function () {
    // Only the exception is asserted: Postgres aborts the transaction after the CHECK error.
    expect(fn () => ProductFactory::new()->create(['servings' => 0]))
        ->toThrow(QueryException::class, 'products_servings_positive');
});

test('refuses a negative servings count', function () {
    expect(fn () => ProductFactory::new()->create(['servings' => -1]))
        ->toThrow(QueryException::class, 'products_servings_positive');
});

test('can be rolled back, dropping the servings column', function () {
    addServingsToProductsMigration()->down();

    expect(Schema::hasColumn('products', 'servings'))->toBeFalse();
});

test('can be re-applied after a rollback, restoring the default and the check', function () {
    $migration = addServingsToProductsMigration();

    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('products', 'servings'))->toBeTrue();

    expect(fn () => ProductFactory::new()->create(['servings' => 0]))
        ->toThrow(QueryException::class, 'products_servings_positive');
});
