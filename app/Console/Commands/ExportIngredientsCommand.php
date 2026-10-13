<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Allergen;
use App\Models\Ingredient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Write the public ingredient dataset (owner_id null only) to a CSV and a JSON file.
 * The CSV uses the exact header and labels that ingredients:import reads, so it can be
 * re-imported unchanged. Nothing here serves or publishes the files.
 */
class ExportIngredientsCommand extends Command
{
    private const CSV_FILE = 'sharlotte-ingredients.csv';

    private const JSON_FILE = 'sharlotte-ingredients.json';

    protected $signature = 'ingredients:export {--directory= : Target directory (defaults to storage/app/public-dataset)}';

    protected $description = 'Export the public ingredient dataset to CSV and JSON files';

    public function handle(): int
    {
        $option = $this->option('directory');
        $directory = is_string($option) && $option !== '' ? $option : storage_path('app/public-dataset');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Unable to create directory: {$directory}");

            return self::FAILURE;
        }

        // Public rows only: this filter is the one thing keeping private user data out of
        // the CC-BY dataset. Ordered by slug so unchanged data gives identical files.
        $rows = Ingredient::query()
            ->whereNull('owner_id')
            ->with(['category', 'allergens', 'allergenTraces'])
            ->orderBy('slug')
            ->get()
            ->map(fn (Ingredient $ingredient): array => $this->toRow($ingredient))
            ->values();

        $generatedAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $csvPath = $directory.'/'.self::CSV_FILE;
        $jsonPath = $directory.'/'.self::JSON_FILE;

        $temporaryFiles = [];

        try {
            // Both temp files are fully written before either is renamed into place.
            $csvTmp = $temporaryFiles[] = $this->temporaryPath($csvPath);
            $this->writeCsv($csvTmp, $rows);

            $jsonTmp = $temporaryFiles[] = $this->temporaryPath($jsonPath);
            $this->writeJson($jsonTmp, $generatedAt, $rows);

            $this->moveIntoPlace($jsonTmp, $jsonPath);
            $this->moveIntoPlace($csvTmp, $csvPath);
        } catch (Throwable $exception) {
            $this->error("Export failed: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            foreach ($temporaryFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        $this->info("CSV: {$csvPath}");
        $this->info("JSON: {$jsonPath}");
        $this->info("Ingredients exported: {$rows->count()}");
        $this->info("Generated at: {$generatedAt}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function toRow(Ingredient $ingredient): array
    {
        $labels = static fn (Collection $allergens): string => $allergens
            ->map(fn (Allergen $allergen): string => $allergen->label_fr)
            ->sort()
            ->implode(', ');

        $values = [
            'Category' => $ingredient->category->label_fr,
            'Name' => $ingredient->name,
            'Storage' => $ingredient->storage->frenchLabel(),
            'Privacy' => 'public',
            'Calories' => $ingredient->calories,
            'Fats' => $ingredient->fats,
            'Saturates' => $ingredient->saturates,
            'Carbohydrates' => $ingredient->carbohydrates,
            'Sugars' => $ingredient->sugars,
            'Fibers' => $ingredient->fibers ?? '',
            'Proteins' => $ingredient->proteins,
            'Salt' => $ingredient->salt,
            'Water' => $ingredient->water ?? '',
            'allergensContained' => $labels($ingredient->allergens),
            'allergensTraces' => $labels($ingredient->allergenTraces),
        ];

        // Emit columns in the importer's own order.
        $row = [];
        foreach (ImportIngredientsCommand::REQUIRED_COLUMNS as $column) {
            $row[$column] = $values[$column];
        }

        return $row;
    }

    private function temporaryPath(string $finalPath): string
    {
        return $finalPath.'.tmp'.bin2hex(random_bytes(4));
    }

    /**
     * Values are written as-is, with no "=", "+", "-" or "@" prefix escaping against spreadsheet
     * formula injection: escaping would change the data and break the re-import round trip, and
     * every row comes from the curated import, not from user input.
     *
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function writeCsv(string $path, Collection $rows): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to write {$path}");
        }

        try {
            fputcsv($handle, ImportIngredientsCommand::REQUIRED_COLUMNS, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function writeJson(string $path, string $generatedAt, Collection $rows): void
    {
        $json = json_encode([
            'generated_at' => $generatedAt,
            'license' => 'CC-BY 4.0',
            'count' => $rows->count(),
            'ingredients' => $rows->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException("Unable to write {$path}");
        }
    }

    private function moveIntoPlace(string $temporaryPath, string $finalPath): void
    {
        // @ silences PHP's warning: the failure is reported through the exception instead.
        if (! @rename($temporaryPath, $finalPath)) {
            throw new RuntimeException("Unable to move the export into place: {$finalPath}");
        }
    }
}
