<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\IngredientStorage;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Import (upsert) the public ingredient database from a CSV export of the source
 * spreadsheet. This is the cleaning pass, not a separate manual step: it is safe and
 * expected to be re-run repeatedly as the user keeps tweaking the spreadsheet, and it
 * reports problems per row instead of aborting the whole run on one bad row.
 *
 * No Composer package (PhpSpreadsheet, maatwebsite/excel) is used to parse the source
 * file — the user exports the Excel sheet to UTF-8 CSV himself, and PHP's built-in
 * fgetcsv() is enough for a single, well-understood column layout; a dependency would buy
 * native .xlsx reading we don't need, at the cost of one more thing to keep updated.
 */
class ImportIngredientsCommand extends Command
{
    protected $signature = 'ingredients:import {path : Path to the UTF-8 CSV export of the ingredient spreadsheet}';

    protected $description = 'Import (upsert) the public ingredient database from a CSV export of the source spreadsheet';

    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'Category',
        'Name',
        'Storage',
        'Privacy',
        'Calories',
        'Fats',
        'Saturates',
        'Carbohydrates',
        'Sugars',
        'Fibers',
        'Proteins',
        'Salt',
        'Water',
        'allergensContained',
        'allergensTraces',
    ];

    // Grams-per-100g fields cannot exceed 100g of the ingredient itself, by definition.
    private const MAX_GRAMS_PER_100G = 100.0;

    // Generous ceiling: pure fat/oil tops out around 900 kcal/100g.
    private const MAX_CALORIES = 900;

    /** @var list<string> */
    private array $errors = [];

    private int $imported = 0;

    private int $updated = 0;

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $stream = fopen($path, 'r');

        if ($stream === false) {
            $this->error("Unable to open file: {$path}");

            return self::FAILURE;
        }

        try {
            return $this->import($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function import($stream): int
    {
        $delimiter = $this->detectDelimiter($stream);
        $header = $this->readHeader($stream, $delimiter);

        if ($header === null) {
            return self::FAILURE;
        }

        $categories = IngredientCategory::query()->get()->keyBy(
            fn (IngredientCategory $category): string => $this->normalize($category->label_fr)
        );

        $allergens = Allergen::query()->get()->keyBy(
            fn (Allergen $allergen): string => $this->normalize($allergen->label_fr)
        );

        if ($categories->isEmpty()) {
            $this->error('No ingredient categories are seeded — run IngredientCategorySeeder first.');

            return self::FAILURE;
        }

        /** @var array<string, int> $seenSlugs slug => row number it was first produced on */
        $seenSlugs = [];
        $rowNumber = 1; // the header occupies row 1

        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            $rowNumber++;

            if (count($row) === 1 && $row[0] === null) {
                continue; // blank trailing line
            }

            if (count($row) !== count($header)) {
                $this->errors[] = "Row {$rowNumber}: expected ".count($header).' columns, got '.count($row);

                continue;
            }

            /** @var array<string, string> $cells */
            $cells = array_combine($header, array_map(
                static fn (mixed $value): string => trim((string) $value),
                $row
            ));

            try {
                $this->importRow($cells, $rowNumber, $categories, $allergens, $seenSlugs);
            } catch (Throwable $exception) {
                $this->errors[] = "Row {$rowNumber}: {$exception->getMessage()}";
            }
        }

        $this->report();

        return self::SUCCESS;
    }

    /**
     * @param  resource  $stream
     */
    private function detectDelimiter($stream): string
    {
        $firstLine = fgets($stream);
        rewind($stream);

        if ($firstLine === false) {
            return ',';
        }

        // French-authored Excel exports plausibly use ';' instead of ',' — detect rather
        // than assume, since decimal values in the same file may already use ','.
        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @param  resource  $stream
     * @return list<string>|null
     */
    private function readHeader($stream, string $delimiter): ?array
    {
        $header = fgetcsv($stream, 0, $delimiter);

        if ($header === false || $header === null) {
            $this->error('Could not read the CSV header row.');

            return null;
        }

        $header = array_map(static fn (mixed $value): string => trim((string) $value), $header);

        // Excel sometimes prepends a UTF-8 BOM to the first header cell.
        if ($header !== [] && str_starts_with($header[0], "\u{FEFF}")) {
            $header[0] = substr($header[0], strlen("\u{FEFF}"));
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);

        if ($missing !== []) {
            $this->error('Missing required column(s): '.implode(', ', $missing));

            return null;
        }

        return $header;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  Collection<string, IngredientCategory>  $categories
     * @param  Collection<string, Allergen>  $allergens
     * @param  array<string, int>  $seenSlugs
     */
    private function importRow(array $cells, int $rowNumber, Collection $categories, Collection $allergens, array &$seenSlugs): void
    {
        $name = trim($cells['Name']);

        if ($name === '') {
            throw new InvalidArgumentException('missing Name');
        }

        $category = $categories->get($this->normalize($cells['Category']));

        if ($category === null) {
            throw new InvalidArgumentException("unrecognized Category '{$cells['Category']}'");
        }

        $storage = IngredientStorage::fromFrenchLabel($cells['Storage']);

        if ($storage === null) {
            throw new InvalidArgumentException("unrecognized Storage '{$cells['Storage']}' (expected frais/surgelé/sec)");
        }

        if ($this->normalize($cells['Privacy']) !== 'public') {
            throw new InvalidArgumentException(
                "unsupported Privacy value '{$cells['Privacy']}' — this command only imports public rows (the source file has no owner column to attribute a private row to)"
            );
        }

        $slug = Ingredient::buildSlug($name, $storage, null);

        if (isset($seenSlugs[$slug])) {
            throw new InvalidArgumentException("duplicate slug '{$slug}', already produced by row {$seenSlugs[$slug]}");
        }

        $calories = $this->parseCalories($cells['Calories']);

        $nutrition = [
            'fats' => $this->parseGrams($cells['Fats'], 'Fats'),
            'saturates' => $this->parseGrams($cells['Saturates'], 'Saturates'),
            'carbohydrates' => $this->parseGrams($cells['Carbohydrates'], 'Carbohydrates'),
            'sugars' => $this->parseGrams($cells['Sugars'], 'Sugars'),
            'fibers' => $this->parseGrams($cells['Fibers'], 'Fibers'),
            'proteins' => $this->parseGrams($cells['Proteins'], 'Proteins'),
            'salt' => $this->parseGrams($cells['Salt'], 'Salt'),
            'water' => $this->parseGrams($cells['Water'], 'Water'),
        ];

        $contains = $this->resolveAllergens($cells['allergensContained'], 'allergensContained', $allergens);
        $traces = $this->resolveAllergens($cells['allergensTraces'], 'allergensTraces', $allergens);

        // Recorded before the DB write so a slug collision is still caught even if the
        // write itself fails and is reported below.
        $seenSlugs[$slug] = $rowNumber;

        DB::transaction(function () use ($category, $name, $slug, $storage, $calories, $nutrition, $contains, $traces): void {
            $ingredient = Ingredient::query()->updateOrCreate(
                ['slug' => $slug],
                array_merge([
                    'category_id' => $category->id,
                    'owner_id' => null,
                    'name' => $name,
                    'storage' => $storage,
                    'calories' => $calories,
                ], $nutrition)
            );

            $ingredient->allergens()->sync($contains);
            $ingredient->allergenTraces()->sync($traces);

            if ($ingredient->wasRecentlyCreated) {
                $this->imported++;
            } else {
                $this->updated++;
            }
        });
    }

    private function parseCalories(string $raw): ?int
    {
        $value = $this->parseDecimal($raw, 'Calories');

        if ($value === null) {
            return null;
        }

        if ($value < 0 || $value > self::MAX_CALORIES) {
            throw new InvalidArgumentException(sprintf('Calories out of range (0-%d): %s', self::MAX_CALORIES, $value));
        }

        return (int) round($value);
    }

    private function parseGrams(string $raw, string $field): ?float
    {
        $value = $this->parseDecimal($raw, $field);

        if ($value === null) {
            return null;
        }

        if ($value < 0 || $value > self::MAX_GRAMS_PER_100G) {
            throw new InvalidArgumentException(sprintf('%s out of the expected 0-%sg/100g range: %s', $field, self::MAX_GRAMS_PER_100G, $value));
        }

        return $value;
    }

    /**
     * Parse a French-locale numeric cell (comma or dot decimal separator) into a float.
     * A blank cell means "unknown data point" and returns null — it is never coerced to 0,
     * since for these fields (especially Water) 0 and "unknown" are different facts.
     */
    private function parseDecimal(string $raw, string $field): ?float
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $trimmed);

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("{$field} is not a valid number: '{$raw}'");
        }

        return round((float) $normalized, 2);
    }

    /**
     * @param  Collection<string, Allergen>  $allergens
     * @return list<int>
     */
    private function resolveAllergens(string $raw, string $field, Collection $allergens): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $trimmed) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            $allergen = $allergens->get($this->normalize($token));

            if ($allergen === null) {
                throw new InvalidArgumentException("unrecognized allergen '{$token}' in {$field}");
            }

            $ids[] = $allergen->id;
        }

        return $ids;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->trim()->ascii()->lower()->toString();
    }

    private function report(): void
    {
        $this->newLine();
        $this->info("Imported: {$this->imported}");
        $this->info("Updated: {$this->updated}");

        if ($this->errors === []) {
            $this->info('No errors.');

            return;
        }

        $this->warn(count($this->errors).' row(s) skipped:');

        foreach ($this->errors as $error) {
            $this->line("  - {$error}");
        }
    }
}
