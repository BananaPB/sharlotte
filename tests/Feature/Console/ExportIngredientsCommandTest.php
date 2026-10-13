<?php

declare(strict_types=1);

use App\Enums\IngredientStorage;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use Database\Factories\IngredientFactory;
use Database\Seeders\AllergenSeeder;
use Database\Seeders\IngredientCategorySeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->seed(AllergenSeeder::class);
    $this->seed(IngredientCategorySeeder::class);

    $this->exportDir = sys_get_temp_dir().'/ingredients_export_test_'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->exportDir);
});

/**
 * @return list<array<string, string>> CSV rows keyed by header name
 */
function readExportedCsv(string $path): array
{
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle, 0, ',');
    $rows = [];

    while (($row = fgetcsv($handle, 0, ',')) !== false) {
        $rows[] = array_combine($header, $row);
    }

    fclose($handle);

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function readExportedJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('writes the CSV and JSON files, creating the directory when missing', function (): void {
    IngredientFactory::new()->create();

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    expect($this->exportDir.'/sharlotte-ingredients.csv')->toBeFile()
        ->and($this->exportDir.'/sharlotte-ingredients.json')->toBeFile();
});

it('writes to storage/app/public-dataset by default', function (): void {
    $default = storage_path('app/public-dataset');
    File::deleteDirectory($default);

    IngredientFactory::new()->create();
    $this->artisan('ingredients:export')->assertSuccessful();

    expect($default.'/sharlotte-ingredients.csv')->toBeFile();

    File::deleteDirectory($default);
});

it('leaves private ingredients out of both files', function (): void {
    IngredientFactory::new()->create(['name' => 'Farine publique', 'slug' => 'farine-publique-ambient']);
    IngredientFactory::new()->private()->create(['name' => 'Secret perso']);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    $csv = (string) file_get_contents($this->exportDir.'/sharlotte-ingredients.csv');
    $json = (string) file_get_contents($this->exportDir.'/sharlotte-ingredients.json');

    expect($csv)->toContain('Farine publique')->not->toContain('Secret perso')
        ->and($json)->toContain('Farine publique')->not->toContain('Secret perso')
        ->and(readExportedJson($this->exportDir.'/sharlotte-ingredients.json')['count'])->toBe(1);
});

it('uses the header names and labels that the importer reads', function (): void {
    $category = IngredientCategory::query()->firstOrFail();
    $gluten = Allergen::query()->orderBy('id')->firstOrFail();
    $trace = Allergen::query()->orderBy('id')->skip(1)->firstOrFail();

    $ingredient = IngredientFactory::new()->create([
        'category_id' => $category->id,
        'name' => 'Beurre',
        'slug' => 'beurre-frozen',
        'storage' => IngredientStorage::Frozen,
        'calories' => 717.5,
        'fats' => 81,
        'saturates' => 51,
        'carbohydrates' => 0.1,
        'sugars' => 0.1,
        'fibers' => null,
        'proteins' => 0.9,
        'salt' => 0.02,
        'water' => 16,
    ]);
    $ingredient->allergens()->sync([$gluten->id]);
    $ingredient->allergenTraces()->sync([$trace->id]);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    $path = $this->exportDir.'/sharlotte-ingredients.csv';
    $headerLine = strtok((string) file_get_contents($path), "\n");

    expect($headerLine)->toBe(
        'Category,Name,Storage,Privacy,Calories,Fats,Saturates,Carbohydrates,Sugars,Fibers,Proteins,Salt,Water,allergensContained,allergensTraces'
    );

    expect(readExportedCsv($path)[0])->toBe([
        'Category' => $category->label_fr,
        'Name' => 'Beurre',
        'Storage' => 'surgelé',
        'Privacy' => 'public',
        'Calories' => '717.50',
        'Fats' => '81.00',
        'Saturates' => '51.00',
        'Carbohydrates' => '0.10',
        'Sugars' => '0.10',
        'Fibers' => '',
        'Proteins' => '0.90',
        'Salt' => '0.02',
        'Water' => '16.00',
        'allergensContained' => $gluten->label_fr,
        'allergensTraces' => $trace->label_fr,
    ]);
});

it('orders rows by slug', function (): void {
    IngredientFactory::new()->create(['name' => 'Zeste', 'slug' => 'zeste-fresh', 'storage' => IngredientStorage::Fresh]);
    IngredientFactory::new()->create(['name' => 'Ail', 'slug' => 'ail-fresh', 'storage' => IngredientStorage::Fresh]);
    IngredientFactory::new()->create(['name' => 'Miel', 'slug' => 'miel-fresh', 'storage' => IngredientStorage::Fresh]);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    $names = array_column(readExportedCsv($this->exportDir.'/sharlotte-ingredients.csv'), 'Name');
    $jsonNames = array_column(readExportedJson($this->exportDir.'/sharlotte-ingredients.json')['ingredients'], 'Name');

    expect($names)->toBe(['Ail', 'Miel', 'Zeste'])
        ->and($jsonNames)->toBe(['Ail', 'Miel', 'Zeste']);
});

it('wraps the JSON in an envelope with timestamp, licence and count', function (): void {
    IngredientFactory::new()->count(2)->create();

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])
        ->expectsOutputToContain($this->exportDir.'/sharlotte-ingredients.csv')
        ->expectsOutputToContain($this->exportDir.'/sharlotte-ingredients.json')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    $data = readExportedJson($this->exportDir.'/sharlotte-ingredients.json');

    expect(array_keys($data))->toBe(['generated_at', 'license', 'count', 'ingredients'])
        ->and($data['license'])->toBe('CC-BY 4.0')
        ->and($data['count'])->toBe(2)
        ->and($data['ingredients'])->toHaveCount(2)
        ->and($data['generated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(array_keys($data['ingredients'][0]))->toBe([
            'Category', 'Name', 'Storage', 'Privacy', 'Calories', 'Fats', 'Saturates',
            'Carbohydrates', 'Sugars', 'Fibers', 'Proteins', 'Salt', 'Water',
            'allergensContained', 'allergensTraces',
        ]);
});

it('produces identical files for unchanged data apart from generated_at', function (): void {
    IngredientFactory::new()->count(3)->create();

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();
    $csvFirst = file_get_contents($this->exportDir.'/sharlotte-ingredients.csv');
    $jsonFirst = readExportedJson($this->exportDir.'/sharlotte-ingredients.json');

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();
    $jsonSecond = readExportedJson($this->exportDir.'/sharlotte-ingredients.json');

    unset($jsonFirst['generated_at'], $jsonSecond['generated_at']);

    expect(file_get_contents($this->exportDir.'/sharlotte-ingredients.csv'))->toBe($csvFirst)
        ->and($jsonSecond)->toBe($jsonFirst);
});

it('leaves no partial or temporary file behind when writing fails', function (): void {
    IngredientFactory::new()->create();
    mkdir($this->exportDir, 0777, true);
    file_put_contents($this->exportDir.'/sharlotte-ingredients.csv', 'OLD');
    // A directory squatting on the JSON target makes the final rename fail.
    mkdir($this->exportDir.'/sharlotte-ingredients.json');

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertFailed();

    expect(file_get_contents($this->exportDir.'/sharlotte-ingredients.csv'))->toBe('OLD')
        ->and(glob($this->exportDir.'/*.tmp*') ?: [])->toBe([]);
});

it('round-trips through ingredients:import field by field', function (): void {
    $category = IngredientCategory::query()->firstOrFail();
    $allergens = Allergen::query()->orderBy('id')->take(2)->get();

    $a = IngredientFactory::new()->create([
        'category_id' => $category->id,
        'name' => 'Crème, épaisse',
        'slug' => Ingredient::buildSlug('Crème, épaisse', IngredientStorage::Fresh, null),
        'storage' => IngredientStorage::Fresh,
        'calories' => 292.35,
        'fibers' => null,
        'water' => 60.5,
    ]);
    $a->allergens()->sync($allergens->pluck('id')->all());
    $a->allergenTraces()->sync([$allergens[0]->id]);

    IngredientFactory::new()->create([
        'name' => 'Petits pois',
        'slug' => Ingredient::buildSlug('Petits pois', IngredientStorage::Frozen, null),
        'storage' => IngredientStorage::Frozen,
    ]);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    $snapshot = fn () => Ingredient::query()
        ->whereNull('owner_id')
        ->orderBy('slug')
        ->with(['allergens', 'allergenTraces'])
        ->get()
        ->map(fn (Ingredient $i): array => [
            ...$i->only([
                'category_id', 'name', 'slug', 'storage', 'calories', 'fats', 'saturates',
                'carbohydrates', 'sugars', 'fibers', 'proteins', 'salt', 'water',
            ]),
            'allergens' => $i->allergens->pluck('id')->sort()->values()->all(),
            'traces' => $i->allergenTraces->pluck('id')->sort()->values()->all(),
        ])
        ->all();

    $original = $snapshot();

    Ingredient::query()->delete();
    expect(Ingredient::query()->count())->toBe(0);

    $this->artisan('ingredients:import', ['path' => $this->exportDir.'/sharlotte-ingredients.csv'])->assertSuccessful();

    expect($snapshot())->toEqual($original);
});

it('exports an empty but valid dataset when there are no public ingredients', function (): void {
    IngredientFactory::new()->private()->create(['name' => 'Secret perso']);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    $data = readExportedJson($this->exportDir.'/sharlotte-ingredients.json');

    expect($data['count'])->toBe(0)
        ->and($data['ingredients'])->toBe([])
        ->and(readExportedCsv($this->exportDir.'/sharlotte-ingredients.csv'))->toBe([]);
});

it('does not leak a private ingredient even when it carries allergens', function (): void {
    $private = IngredientFactory::new()->private()->create(['name' => 'Confidentiel']);
    $private->allergens()->sync([Allergen::query()->firstOrFail()->id]);
    IngredientFactory::new()->create(['name' => 'Sel public']);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    $names = array_column(readExportedCsv($this->exportDir.'/sharlotte-ingredients.csv'), 'Name');
    $jsonNames = array_column(readExportedJson($this->exportDir.'/sharlotte-ingredients.json')['ingredients'], 'Name');

    expect($names)->toBe(['Sel public'])
        ->and($jsonNames)->toBe(['Sel public']);
});

it('gives the JSON the same rows as the CSV', function (): void {
    $ingredient = IngredientFactory::new()->create(['name' => 'Pain', 'fibers' => null]);
    $ingredient->allergens()->sync([Allergen::query()->orderBy('id')->firstOrFail()->id]);
    IngredientFactory::new()->create(['name' => 'Lait']);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    expect(readExportedJson($this->exportDir.'/sharlotte-ingredients.json')['ingredients'])
        ->toBe(readExportedCsv($this->exportDir.'/sharlotte-ingredients.csv'));
});

it('overwrites files from a previous export and leaves no temporary file on success', function (): void {
    mkdir($this->exportDir, 0777, true);
    file_put_contents($this->exportDir.'/sharlotte-ingredients.csv', 'OLD');
    file_put_contents($this->exportDir.'/sharlotte-ingredients.json', 'OLD');
    IngredientFactory::new()->create(['name' => 'Nouveau']);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    expect(file_get_contents($this->exportDir.'/sharlotte-ingredients.csv'))->toContain('Nouveau')
        ->and(readExportedJson($this->exportDir.'/sharlotte-ingredients.json')['count'])->toBe(1)
        ->and(array_map('basename', glob($this->exportDir.'/*') ?: []))
        ->toEqualCanonicalizing(['sharlotte-ingredients.csv', 'sharlotte-ingredients.json']);
});

it('keeps names with quotes, newlines and accents intact in both files', function (): void {
    $name = "Sauce \"maison\" à l'ail\nrôtie";
    IngredientFactory::new()->create(['name' => $name]);

    $this->artisan('ingredients:export', ['--directory' => $this->exportDir])->assertSuccessful();

    expect(readExportedCsv($this->exportDir.'/sharlotte-ingredients.csv')[0]['Name'])->toBe($name)
        ->and(readExportedJson($this->exportDir.'/sharlotte-ingredients.json')['ingredients'][0]['Name'])->toBe($name);
});

it('is scheduled weekly', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'ingredients:export'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * 0');
});
