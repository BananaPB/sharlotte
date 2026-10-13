<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Contracts\HasRecipe;
use App\Models\Preparation;
use App\Models\RecipeLine;
use Illuminate\Support\Collection;
use UnexpectedValueException;

/**
 * Reduces a product or preparation to its raw ingredients, in grams (docs/domain-model.md,
 * "Unrolling a recipe"; scaling rule in docs/decisions.md entry 10).
 *
 * Loading: recipes are read one tree level at a time (one query for the lines of every
 * preparation of that level, with their units), so the number of queries depends on the
 * depth of the tree, never on its width. All calculation then happens in memory.
 *
 * Arithmetic: BCMath on strings only, scale 8 for intermediate values, rounded half-up to
 * 4 decimals at the end. No floats, so results are exact and repeatable.
 *
 * It reads whatever the recipe points at: users, visibility and policies are not its concern.
 */
class RecipeFlattener
{
    private const WORKING_SCALE = 8;

    private const RESULT_SCALE = 4;

    /** Half of the last result decimal, added before truncating to round half-up. */
    private const HALF_UP = '0.00005';

    /**
     * Recipe lines of every loaded preparation: preparation id => its lines (units loaded).
     *
     * @var array<int, Collection<int, RecipeLine>>
     */
    private array $linesOf = [];

    /**
     * Flattened preparations already computed: id => [ingredient id => grams, recipe weight].
     * A preparation used several times (or in a diamond) is only computed once.
     *
     * @var array<int, array{0: array<int, numeric-string>, 1: numeric-string}>
     */
    private array $computed = [];

    /**
     * @return array<int, numeric-string> ingredient id => total grams (4 decimals), sorted by ingredient id
     */
    public function flatten(HasRecipe $recipe): array
    {
        $this->linesOf = [];
        $this->computed = [];

        $rootLines = $recipe->recipeLines()->with('unit')->get();
        $path = [];

        if ($recipe instanceof Preparation) {
            $this->linesOf[$recipe->id] = $rootLines;
            $path = [$recipe->id];
        }

        $this->loadComponents($rootLines);

        [$totals] = $this->unroll($rootLines, $path);

        ksort($totals);

        return array_map(
            fn (string $grams): string => bcadd(bcadd($grams, self::HALF_UP, self::WORKING_SCALE), '0', self::RESULT_SCALE),
            $totals,
        );
    }

    /**
     * Loads, level by level, the lines of every preparation reachable from $lines.
     *
     * @param  Collection<int, RecipeLine>  $lines
     */
    private function loadComponents(Collection $lines): void
    {
        $level = $lines;

        while (true) {
            $wanted = $level
                ->pluck('component_preparation_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->reject(fn (int $id): bool => isset($this->linesOf[$id]))
                ->values();

            if ($wanted->isEmpty()) {
                return;
            }

            $byParent = RecipeLine::query()
                ->whereIn('parent_preparation_id', $wanted->all())
                ->orderBy('position')
                ->orderBy('id')
                ->with('unit')
                ->get()
                ->groupBy('parent_preparation_id');

            $level = new Collection;
            foreach ($wanted as $id) {
                /** @var Collection<int, RecipeLine> $lines */
                $lines = $byParent->get($id, new Collection);
                $this->linesOf[$id] = $lines;
                $level = $level->concat($lines);
            }
        }
    }

    /**
     * Grams of each raw ingredient in a recipe made of $lines, and the recipe's weight (the sum
     * of its lines in grams). $path holds the preparations being unrolled, to spot a cycle.
     *
     * @param  Collection<int, RecipeLine>  $lines
     * @param  list<int>  $path
     * @return array{0: array<int, numeric-string>, 1: numeric-string}
     */
    private function unroll(Collection $lines, array $path): array
    {
        $totals = [];
        $weight = bcadd('0', '0', self::WORKING_SCALE);

        foreach ($lines as $line) {
            $grams = $this->gramsOf($line);
            $weight = bcadd($weight, $grams, self::WORKING_SCALE);

            if ($line->ingredient_id !== null) {
                $this->add($totals, (int) $line->ingredient_id, $grams);

                continue;
            }

            [$componentTotals, $componentWeight] = $this->unrollPreparation((int) $line->component_preparation_id, $path);

            // A preparation that weighs nothing has nothing to contribute (and cannot be divided by).
            if (bccomp($componentWeight, '0', self::WORKING_SCALE) === 0) {
                continue;
            }

            foreach ($componentTotals as $ingredientId => $componentGrams) {
                // Multiply before dividing: one rounding step instead of two.
                $share = bcdiv(bcmul($componentGrams, $grams, self::WORKING_SCALE), $componentWeight, self::WORKING_SCALE);
                $this->add($totals, $ingredientId, $share);
            }
        }

        return [$totals, $weight];
    }

    /**
     * @param  list<int>  $path
     * @return array{0: array<int, numeric-string>, 1: numeric-string}
     */
    private function unrollPreparation(int $preparationId, array $path): array
    {
        // Being inside this preparation already: the data contains a cycle. A preparation merely
        // computed earlier (reused, or a diamond) is not on the current path, so it is no cycle.
        if (in_array($preparationId, $path, true)) {
            $cycle = array_slice($path, (int) array_search($preparationId, $path, true));

            throw $this->cycleException($cycle, $preparationId);
        }

        return $this->computed[$preparationId] ??= $this->unroll(
            $this->linesOf[$preparationId],
            [...$path, $preparationId],
        );
    }

    /**
     * @param  list<int>  $cycle  preparations from the first one met again to the last, in order
     */
    private function cycleException(array $cycle, int $backTo): RecipeCycleException
    {
        // Names are only looked up on the failure path, to build a readable message.
        $names = Preparation::query()->whereIn('id', $cycle)->pluck('name', 'id');

        $described = array_map(
            fn (int $id): string => sprintf('"%s" (#%d)', (string) $names->get($id), $id),
            [...$cycle, $backTo],
        );

        return new RecipeCycleException('Recipe data contains a cycle: '.implode(' -> ', $described).'.');
    }

    /**
     * @param  array<int, numeric-string>  $totals
     * @param  numeric-string  $grams
     */
    private function add(array &$totals, int $ingredientId, string $grams): void
    {
        $totals[$ingredientId] = bcadd($totals[$ingredientId] ?? '0', $grams, self::WORKING_SCALE);
    }

    /**
     * A line's quantity in grams: as is without a unit, quantity x unit grams with one.
     *
     * @return numeric-string
     */
    private function gramsOf(RecipeLine $line): string
    {
        $quantity = $this->numeric($line->quantity);

        if ($line->unit === null) {
            return bcadd($quantity, '0', self::WORKING_SCALE);
        }

        return bcmul($quantity, $this->numeric($line->unit->grams), self::WORKING_SCALE);
    }

    /**
     * BCMath only accepts numeric strings: a non-numeric database value is a data error, said loudly.
     *
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        $text = (string) $value;

        if (! is_numeric($text)) {
            throw new UnexpectedValueException('Recipe data contains a non-numeric amount: "'.$text.'".');
        }

        return $text;
    }
}
