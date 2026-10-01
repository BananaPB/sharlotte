<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a recipe (docs/decisions.md entry 8), shared by preparations and products.
 *
 * - Parent: a preparation XOR a product (DB CHECK `recipe_lines_one_parent`).
 * - Component: an ingredient XOR a preparation (DB CHECK `recipe_lines_one_component`).
 *   There is no product component column: a product can never be a component.
 * - `unit_id` null means `quantity` is in grams; with a unit, `quantity` is a count of that
 *   unit (2 x "1 tranche = 40 g").
 *
 * @property int $id
 * @property int|null $parent_preparation_id
 * @property int|null $product_id
 * @property int|null $ingredient_id
 * @property int|null $component_preparation_id
 * @property int|null $unit_id
 * @property int $position
 * @property string $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Preparation|null $parentPreparation
 * @property-read Product|null $product
 * @property-read Ingredient|null $ingredient
 * @property-read Preparation|null $componentPreparation
 * @property-read Unit|null $unit
 */
class RecipeLine extends Model
{
    // The parent columns (`parent_preparation_id`, `product_id`) are deliberately not
    // fillable: like `owner_id` elsewhere, they decide whose data this is, so a line is always
    // created through its (authorized) parent — $product->recipeLines()->create([...]) —
    // never pointed at a parent by request input. The component and unit are fillable: the
    // saving() hook checks they are visible to the parent's owner.
    /** @var list<string> */
    protected $fillable = [
        'ingredient_id',
        'component_preparation_id',
        'unit_id',
        'position',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            // decimal casts return strings, not floats: nutrition math needs exact decimal
            // arithmetic (see docs/decisions.md #5), not float rounding error.
            'quantity' => 'decimal:2',
        ];
    }

    /**
     * Visibility invariants, thrown rather than silently fixed since reaching them means a
     * caller skipped validation/authorization. Shape rules (exactly one parent, exactly one
     * component, no direct self-reference, quantity > 0) are left to the DB CHECKs.
     *
     * Costs 2 queries per save (parent owner, component owner), 3 with a unit. Everything is
     * queried fresh rather than via relations, which could be stale cached models if an id
     * was changed after they were loaded.
     */
    protected static function booted(): void
    {
        static::saving(function (RecipeLine $line): void {
            $parentOwnerId = $line->parentOwnerId();

            // No (or no existing) parent: the CHECK / foreign key will reject the row.
            if ($parentOwnerId === null) {
                return;
            }

            // An ingredient component must be public or owned by the recipe's owner.
            if ($line->ingredient_id !== null) {
                $ingredient = Ingredient::query()->select(['id', 'owner_id'])->find($line->ingredient_id);

                if ($ingredient !== null && $ingredient->owner_id !== null && (int) $ingredient->owner_id !== $parentOwnerId) {
                    throw new LogicException(sprintf(
                        'Recipe line uses private ingredient #%d, which the recipe\'s owner (#%d) cannot see.',
                        $ingredient->id,
                        $parentOwnerId,
                    ));
                }
            }

            // A component preparation must be owned by the recipe's owner (preparations are always private).
            if ($line->component_preparation_id !== null) {
                $componentOwnerId = Preparation::query()
                    ->whereKey($line->component_preparation_id)
                    ->value('owner_id');

                if ($componentOwnerId !== null && (int) $componentOwnerId !== $parentOwnerId) {
                    throw new LogicException(sprintf(
                        'Recipe line uses preparation #%d, owned by #%d, in a recipe owned by #%d.',
                        $line->component_preparation_id,
                        $componentOwnerId,
                        $parentOwnerId,
                    ));
                }
            }

            if ($line->unit_id !== null) {
                $line->assertUnitFits($parentOwnerId);
            }
        });
    }

    /**
     * @return BelongsTo<Preparation, $this>
     */
    public function parentPreparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class, 'parent_preparation_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return BelongsTo<Preparation, $this>
     */
    public function componentPreparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class, 'component_preparation_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The recipe this line belongs to. Not a relation (it reads whichever of the two is set),
     * so eager-load `parentPreparation` and `product` before calling it in a loop.
     */
    public function parent(): Preparation|Product
    {
        return $this->parentPreparation
            ?? $this->product
            ?? throw new LogicException("Recipe line #{$this->id} has no parent.");
    }

    /**
     * What this line puts in the recipe. Not a relation (it reads whichever of the two is
     * set), so eager-load `ingredient` and `componentPreparation` before calling it in a loop.
     */
    public function component(): Ingredient|Preparation
    {
        return $this->ingredient
            ?? $this->componentPreparation
            ?? throw new LogicException("Recipe line #{$this->id} has no component.");
    }

    /**
     * Owner of whichever parent is set, or null if none is set or it doesn't exist.
     */
    private function parentOwnerId(): ?int
    {
        $ownerId = match (true) {
            $this->parent_preparation_id !== null => Preparation::query()->whereKey($this->parent_preparation_id)->value('owner_id'),
            $this->product_id !== null => Product::query()->whereKey($this->product_id)->value('owner_id'),
            default => null,
        };

        return $ownerId === null ? null : (int) $ownerId;
    }

    /**
     * The unit must weigh this line's own component (no "1 tranche de jambon" on a milk line)
     * and be visible to the recipe's owner (public, or theirs).
     */
    private function assertUnitFits(int $parentOwnerId): void
    {
        $unit = Unit::query()
            ->select(['id', 'ingredient_id', 'preparation_id', 'owner_id'])
            ->find($this->unit_id);

        // A missing unit is left to the foreign key to reject.
        if ($unit === null) {
            return;
        }

        // Compared as nullable ints: either side may come back as a numeric string depending on the DB driver.
        $sameIngredient = $this->ingredient_id !== null && (int) $unit->ingredient_id === (int) $this->ingredient_id;
        $samePreparation = $this->component_preparation_id !== null && (int) $unit->preparation_id === (int) $this->component_preparation_id;

        if (! $sameIngredient && ! $samePreparation) {
            throw new LogicException(sprintf(
                'Unit #%d does not belong to this recipe line\'s component.',
                $unit->id,
            ));
        }

        if ($unit->owner_id !== null && (int) $unit->owner_id !== $parentOwnerId) {
            throw new LogicException(sprintf(
                'Recipe line uses private unit #%d, which the recipe\'s owner (#%d) cannot see.',
                $unit->id,
                $parentOwnerId,
            ));
        }
    }
}
