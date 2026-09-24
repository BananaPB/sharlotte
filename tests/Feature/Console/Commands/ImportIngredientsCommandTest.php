<?php

declare(strict_types=1);

use App\Enums\IngredientStorage;
use App\Models\Allergen;
use App\Models\Ingredient;
use Database\Seeders\AllergenSeeder;
use Database\Seeders\IngredientCategorySeeder;

/**
 * Builds a minimal CSV line, quoting a field only when it contains the delimiter or a quote
 * character — mirrors what a real spreadsheet export does, and is what lets a single cell
 * (e.g. allergensTraces) legitimately hold a comma-separated list of allergens without that
 * comma being mistaken for the column delimiter.
 *
 * @param  list<string>  $fields
 */
function importCsvLine(array $fields, string $delimiter = ','): string
{
    return implode($delimiter, array_map(
        static function (string $field) use ($delimiter): string {
            return str_contains($field, $delimiter) || str_contains($field, '"')
                ? '"'.str_replace('"', '""', $field).'"'
                : $field;
        },
        $fields
    ));
}

/** @var list<string> */
const IMPORT_CSV_HEADER = [
    'Category', 'Name', 'Storage', 'Privacy', 'Calories', 'Fats', 'Saturates',
    'Carbohydrates', 'Sugars', 'Fibers', 'Proteins', 'Salt', 'Water',
    'allergensContained', 'allergensTraces',
];

/**
 * @param  list<list<string>>  $rows
 */
function makeIngredientsCsv(array $rows, string $delimiter = ',', bool $withBom = false): string
{
    $lines = array_map(
        static fn (array $row): string => importCsvLine($row, $delimiter),
        [IMPORT_CSV_HEADER, ...$rows]
    );

    $content = implode("\n", $lines)."\n";

    if ($withBom) {
        $content = "\u{FEFF}".$content;
    }

    $path = tempnam(sys_get_temp_dir(), 'ingredients_import_test_');
    file_put_contents($path, $content);

    return $path;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/ingredients_import_test_*') ?: [] as $file) {
        @unlink($file);
    }
});

describe('with categories and allergens seeded', function () {
    beforeEach(function (): void {
        $this->seed(AllergenSeeder::class);
        $this->seed(IngredientCategorySeeder::class);
    });

    test('imports a valid CSV, creating ingredients with correct attributes and allergen associations', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
            ['Produits laitiers & crèmerie', 'Fromage blanc', 'frais', 'public', '60', '3', '1.9', '4', '4', '0', '8', '0.1', '84', 'Lait', ''],
            ['Farines & graines', 'Farine de blé', 'sec', 'public', '364', '1', '0.2', '76', '0.3', '2.7', '10', '0', '12', 'Gluten', 'Soja,Lupin'],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 3')
            ->expectsOutputToContain('Updated: 0')
            ->expectsOutputToContain('No errors.')
            ->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(3);

        $apple = Ingredient::query()->where('slug', 'pomme-fresh')->firstOrFail();
        expect($apple->calories)->toBe(52)
            ->and((string) $apple->fats)->toBe('0.17')
            ->and((string) $apple->water)->toBe('85.56')
            ->and($apple->storage)->toBe(IngredientStorage::Fresh)
            ->and($apple->owner_id)->toBeNull()
            ->and($apple->allergens()->count())->toBe(0)
            ->and($apple->allergenTraces()->count())->toBe(0);

        $cheese = Ingredient::query()->where('slug', 'fromage-blanc-fresh')->firstOrFail();
        expect($cheese->allergens()->pluck('code')->all())->toBe(['milk'])
            ->and($cheese->allergenTraces()->count())->toBe(0)
            ->and((string) $cheese->fats)->toBe('3.00');

        $flour = Ingredient::query()->where('slug', 'farine-de-ble-ambient')->firstOrFail();
        expect($flour->storage)->toBe(IngredientStorage::Ambient)
            ->and($flour->allergens()->pluck('code')->all())->toBe(['gluten'])
            ->and($flour->allergenTraces()->pluck('code')->sort()->values()->all())->toBe(['lupin', 'soy']);
    });

    test('is idempotent: re-running the same CSV creates no duplicate rows', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
            ['Produits laitiers & crèmerie', 'Fromage blanc', 'frais', 'public', '60', '3', '1.9', '4', '4', '0', '8', '0.1', '84', 'Lait', ''],
            ['Farines & graines', 'Farine de blé', 'sec', 'public', '364', '1', '0.2', '76', '0.3', '2.7', '10', '0', '12', 'Gluten', 'Soja,Lupin'],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])->assertExitCode(0);
        expect(Ingredient::query()->count())->toBe(3);

        // Deliberately not asserting on this second call's "Imported"/"Updated" line counts:
        // Laravel's console Kernel caches its Artisan\Application (and so this command's own
        // instance, with its private counters) across repeated $this->artisan() calls within
        // one test method, so a second in-process call inherits the first call's counts. That
        // never happens for a real CLI invocation (each is its own fresh PHP process — verified
        // manually with two separate `php artisan ingredients:import` runs). The database state
        // asserted below is the real, environment-independent proof of idempotency.
        $this->artisan('ingredients:import', ['path' => $path])->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(3);
    });

    test('updates existing ingredient data when source values change on re-import', function () {
        $firstPath = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ]);
        $this->artisan('ingredients:import', ['path' => $firstPath])->assertExitCode(0);

        $secondPath = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '55', '0.20', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ]);

        // See the "is idempotent" test above for why this second in-process call's own
        // "Imported"/"Updated" output text isn't asserted here — only the resulting database
        // state is, which is the real proof either way.
        $this->artisan('ingredients:import', ['path' => $secondPath])->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(1);

        $apple = Ingredient::query()->where('slug', 'pomme-fresh')->firstOrFail();
        expect($apple->calories)->toBe(55)
            ->and((string) $apple->fats)->toBe('0.20');
    });

    test('re-importing an ingredient never resets an existing to_review flag', function () {
        $firstPath = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ]);
        $this->artisan('ingredients:import', ['path' => $firstPath])->assertExitCode(0);

        // Simulates the project owner flagging the ingredient for review after the import —
        // this is the state the second import below must not silently clobber.
        Ingredient::query()->where('slug', 'pomme-fresh')->firstOrFail()->update(['to_review' => true]);

        // Same slug (matching name+storage), different nutrition values, so the row genuinely
        // goes through updateOrCreate()'s "update" branch rather than being skipped entirely.
        $secondPath = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '55', '0.20', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ]);
        $this->artisan('ingredients:import', ['path' => $secondPath])->assertExitCode(0);

        $apple = Ingredient::query()->where('slug', 'pomme-fresh')->firstOrFail();
        expect($apple->calories)->toBe(55)
            ->and($apple->to_review)->toBeTrue();
    });

    test('skips a row with an unrecognized storage value without aborting the rest of the import', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
            ['Fruits', 'Poire abîmée', 'surgle', 'public', '50', '0.1', '0', '12', '9', '2', '0.2', '0', '84', '', ''],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('1 row(s) skipped')
            ->expectsOutputToContain('unrecognized Storage')
            ->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(1);
    });

    test('skips a row with an out-of-range calorie value without aborting the rest of the import', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
            ['Fruits', 'Huile suspecte', 'frais', 'public', '999', '100', '10', '0', '0', '0', '0', '0', '0', '', ''],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('1 row(s) skipped')
            ->expectsOutputToContain('Calories out of range')
            ->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(1);
    });

    test('flags a duplicate slug within the same file and imports only the first occurrence', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
            ['Fruits', 'Pomme', 'frais', 'public', '53', '0.18', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('1 row(s) skipped')
            ->expectsOutputToContain("duplicate slug 'pomme-fresh'")
            ->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(1);
        expect(Ingredient::query()->firstOrFail()->calories)->toBe(52);
    });

    test('parses a French-locale export: semicolon-delimited with comma decimals', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Poire', 'frais', 'public', '57', '0,1', '0,02', '15,46', '9,8', '3,1', '0,36', '0', '83,8', '', ''],
        ], delimiter: ';');

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->assertExitCode(0);

        $pear = Ingredient::query()->where('slug', 'poire-fresh')->firstOrFail();
        expect((string) $pear->fats)->toBe('0.10')
            ->and((string) $pear->water)->toBe('83.80');
    });

    test('strips a UTF-8 BOM from the header row', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ], withBom: true);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(1);
    });

    test('fails cleanly when the CSV file does not exist', function () {
        $this->artisan('ingredients:import', ['path' => sys_get_temp_dir().'/does-not-exist-ingredients.csv'])
            ->assertExitCode(1);

        expect(Ingredient::query()->count())->toBe(0);
    });

    test('imports a CSV whose header row is lowercase, same as a properly-cased one', function () {
        // Deliberately not reusing makeIngredientsCsv()/IMPORT_CSV_HEADER, which always emits
        // the canonically-cased header — this exercises the real user export's lowercase one.
        $lowercaseHeader = [
            'category', 'name', 'storage', 'privacy', 'calories', 'fats', 'saturates',
            'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water',
            'allergenscontained', 'allergenstraces',
        ];

        $content = implode("\n", [
            importCsvLine($lowercaseHeader),
            importCsvLine(['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', '']),
        ])."\n";

        $path = tempnam(sys_get_temp_dir(), 'ingredients_import_test_');
        file_put_contents($path, $content);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('No errors.')
            ->assertExitCode(0);

        $apple = Ingredient::query()->where('slug', 'pomme-fresh')->firstOrFail();
        expect($apple->calories)->toBe(52)
            ->and($apple->storage)->toBe(IngredientStorage::Fresh);
    });

    test('treats the literal text "null" in optional cells as blank, not as an error', function () {
        $path = makeIngredientsCsv([
            ['Fruits', 'Pomme sans eau', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', 'null', 'null', 'null'],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 1')
            ->expectsOutputToContain('No errors.')
            ->assertExitCode(0);

        $apple = Ingredient::query()->where('slug', 'pomme-sans-eau-fresh')->firstOrFail();
        expect($apple->water)->toBeNull()
            ->and($apple->allergens()->count())->toBe(0)
            ->and($apple->allergenTraces()->count())->toBe(0);
    });

    test('still rejects a literal "null" in Category, quoting the actual raw value in the error', function () {
        $path = makeIngredientsCsv([
            ['null', 'Mystère', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
        ]);

        $this->artisan('ingredients:import', ['path' => $path])
            ->expectsOutputToContain('Imported: 0')
            ->expectsOutputToContain('1 row(s) skipped')
            ->expectsOutputToContain("unrecognized Category 'null'")
            ->assertExitCode(0);

        expect(Ingredient::query()->count())->toBe(0);
    });
});

test('fails cleanly when no ingredient categories are seeded', function () {
    $this->seed(AllergenSeeder::class);

    $path = makeIngredientsCsv([
        ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
    ]);

    $this->artisan('ingredients:import', ['path' => $path])
        ->expectsOutputToContain('No ingredient categories are seeded')
        ->assertExitCode(1);

    expect(Ingredient::query()->count())->toBe(0);
});

test('fails cleanly when no allergens are seeded', function () {
    $this->seed(IngredientCategorySeeder::class);

    $path = makeIngredientsCsv([
        ['Fruits', 'Pomme', 'frais', 'public', '52', '0.17', '0.03', '13.81', '10.39', '2.4', '0.26', '0', '85.56', '', ''],
    ]);

    $this->artisan('ingredients:import', ['path' => $path])
        ->expectsOutputToContain('No allergens are seeded')
        ->assertExitCode(1);

    expect(Ingredient::query()->count())->toBe(0);
    expect(Allergen::query()->count())->toBe(0);
});
