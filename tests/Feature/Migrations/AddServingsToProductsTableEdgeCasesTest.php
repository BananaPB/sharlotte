<?php

declare(strict_types=1);

use App\Models\Preparation;
use App\Models\Product;
use Database\Factories\ProductFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function servingsMigrationUnderAttack(): object
{
    return require database_path('migrations/2026_10_14_090001_add_servings_to_products_table.php');
}

test('gives an existing product row a servings count of 1 when the migration is applied', function () {
    $ownerId = UserFactory::new()->create()->id;
    $migration = servingsMigrationUnderAttack();
    $migration->down();

    DB::table('products')->insert(['name' => 'Ancien', 'owner_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();

    expect(DB::table('products')->where('name', 'Ancien')->value('servings'))->toBe(1);
});

test('refuses to update an existing product to a servings count of 0', function () {
    $product = ProductFactory::new()->create(['servings' => 4]);

    expect(fn () => $product->update(['servings' => 0]))
        ->toThrow(QueryException::class, 'products_servings_positive');
});

test('refuses an explicit null servings count', function () {
    expect(fn () => ProductFactory::new()->create(['servings' => null]))
        ->toThrow(QueryException::class);
});

test('drops the servings check constraint and column when rolled back', function () {
    servingsMigrationUnderAttack()->down();

    $constraint = DB::selectOne(
        "select 1 as found from pg_constraint where conname = 'products_servings_positive'"
    );

    expect($constraint)->toBeNull()
        ->and(Schema::hasColumn('products', 'servings'))->toBeFalse();
});

test('can be rolled back and re-applied twice in a row', function () {
    $migration = servingsMigrationUnderAttack();

    $migration->down();
    $migration->up();
    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('products', 'servings'))->toBeTrue();
});

test('keeps servings off preparations', function () {
    expect(Schema::hasColumn('preparations', 'servings'))->toBeFalse()
        ->and((new Preparation)->getFillable())->not->toContain('servings');
});

test('keeps the owner of a product out of mass assignment', function () {
    expect((new Product)->getFillable())->toBe(['name', 'servings']);
});

test('stores servings as a not-null small integer defaulting to 1', function () {
    $column = DB::selectOne(
        "select data_type, is_nullable, column_default from information_schema.columns
         where table_name = 'products' and column_name = 'servings'"
    );

    expect($column->data_type)->toBe('smallint')
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toContain("'1'");
});

test('refuses a servings count too large for a small integer', function () {
    expect(fn () => ProductFactory::new()->create(['servings' => 70000]))
        ->toThrow(QueryException::class);
});

test('reads a servings count of 12 back from the database as an integer', function () {
    $product = ProductFactory::new()->create(['servings' => 12]);

    expect(Product::query()->findOrFail($product->id)->servings)->toBe(12);
});

test('produces a product with 1 serving by default from the factory', function () {
    expect(ProductFactory::new()->make()->servings)->toBe(1);
});
