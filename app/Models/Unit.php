<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Gives one Format a weight in grams for one specific component, e.g. for the ingredient
 * "Jambon", "1 tranche = 40 g" (docs/domain-model.md §Units). Recipes always reason in
 * grams; a unit is only a friendlier way to enter a quantity.
 *
 * The component is either an ingredient or a preparation, exactly one of them (DB CHECK
 * `units_one_component`).
 *
 * `owner_id` null means a public unit, visible to everyone. Privacy is derived from it
 * (see isPublic()) rather than stored, so the two can never drift apart.
 *
 * @property int $id
 * @property int|null $ingredient_id
 * @property int|null $preparation_id
 * @property int $format_id
 * @property int|null $owner_id
 * @property string $grams
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient|null $ingredient
 * @property-read Preparation|null $preparation
 * @property-read Format $format
 * @property-read User|null $owner
 */
class Unit extends Model
{
    // `owner_id` is deliberately not fillable: ownership is always set explicitly from the
    // authenticated user ($unit->owner()->associate($user)), never mass-assigned, so request
    // input can never choose the owner.
    /** @var list<string> */
    protected $fillable = ['ingredient_id', 'preparation_id', 'format_id', 'grams'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // decimal casts return strings, not floats: nutrition math needs exact decimal
            // arithmetic (see docs/decisions.md #5), not float rounding error.
            'grams' => 'decimal:2',
        ];
    }

    /**
     * A unit on a private component must belong to that component's owner: a public unit
     * (or someone else's) on it would expose or reference data only its owner may see.
     * Thrown rather than silently fixed, since reaching this means a caller skipped
     * validation/authorization. A private unit on a public ingredient is fine. Preparations
     * are always private, so a unit on one always needs the preparation's owner.
     *
     * Costs one query per save (the component's owner). "Exactly one component" is left to
     * the units_one_component CHECK.
     */
    protected static function booted(): void
    {
        static::saving(function (Unit $unit): void {
            // Owners are queried fresh rather than via the relations, which could be stale
            // cached models if a component id was changed after it was loaded.
            if ($unit->ingredient_id !== null) {
                $ingredientOwnerId = Ingredient::query()
                    ->whereKey($unit->ingredient_id)
                    ->value('owner_id');

                if ($ingredientOwnerId !== null && ! self::sameOwner($unit->owner_id, $ingredientOwnerId)) {
                    throw new LogicException(sprintf(
                        'Unit on private ingredient #%d must be owned by that ingredient\'s owner (#%d), got %s.',
                        $unit->ingredient_id,
                        $ingredientOwnerId,
                        self::describeOwner($unit->owner_id),
                    ));
                }
            }

            if ($unit->preparation_id !== null) {
                $preparationOwnerId = Preparation::query()
                    ->whereKey($unit->preparation_id)
                    ->value('owner_id');

                // A missing preparation is left to the foreign key to reject.
                if ($preparationOwnerId !== null && ! self::sameOwner($unit->owner_id, $preparationOwnerId)) {
                    throw new LogicException(sprintf(
                        'Unit on preparation #%d must be owned by that preparation\'s owner (#%d), got %s.',
                        $unit->preparation_id,
                        $preparationOwnerId,
                        self::describeOwner($unit->owner_id),
                    ));
                }
            }
        });
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
    public function preparation(): BelongsTo
    {
        return $this->belongsTo(Preparation::class);
    }

    /**
     * The ingredient or preparation this unit weighs. Not a relation (it reads whichever of
     * the two is set), so eager-load `ingredient` and `preparation` before calling it in a loop.
     */
    public function component(): Ingredient|Preparation
    {
        return $this->ingredient
            ?? $this->preparation
            ?? throw new LogicException("Unit #{$this->id} has no component.");
    }

    /**
     * @return BelongsTo<Format, $this>
     */
    public function format(): BelongsTo
    {
        return $this->belongsTo(Format::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isPublic(): bool
    {
        return $this->owner_id === null;
    }

    /**
     * Units a given user may see and pick: every public unit, plus their own private ones.
     *
     * @param  Builder<Unit>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->whereNull('owner_id')->orWhere('owner_id', $user->id);
        });
    }

    /**
     * Compared as ints: either side may come back as a numeric string depending on the DB driver.
     */
    private static function sameOwner(int|string|null $ownerId, int|string $expectedOwnerId): bool
    {
        return $ownerId !== null && (int) $ownerId === (int) $expectedOwnerId;
    }

    private static function describeOwner(int|string|null $ownerId): string
    {
        return $ownerId === null ? 'a public unit' : "owner #{$ownerId}";
    }
}
