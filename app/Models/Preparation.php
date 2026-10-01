<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\HasRecipe;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An intermediate composition with its own recipe, reusable as a component of other
 * preparations and of products — the middle of the Product > Preparation > Ingredient tree
 * (docs/domain-model.md). Always owned by a user: there is no public tier
 * (docs/decisions.md entry 10). No yield either: its weight is the sum of its lines.
 *
 * @property int $id
 * @property string $name
 * @property int $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Collection<int, RecipeLine> $recipeLines
 * @property-read Collection<int, RecipeLine> $usedInLines
 * @property-read Collection<int, Unit> $units
 */
class Preparation extends Model implements HasRecipe
{
    // `owner_id` is deliberately not fillable: ownership is always set explicitly from the
    // authenticated user ($preparation->owner()->associate($user)), never mass-assigned.
    /** @var list<string> */
    protected $fillable = ['name'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * This preparation's own recipe.
     *
     * @return HasMany<RecipeLine, $this>
     */
    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'parent_preparation_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Lines of other recipes that use this preparation as a component. While any exist, the
     * database refuses to delete it.
     *
     * @return HasMany<RecipeLine, $this>
     */
    public function usedInLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'component_preparation_id');
    }

    /**
     * Gram weights for this preparation's formats (e.g. "1 part = 80 g"). Always private,
     * owned by the preparation's owner (see Unit's saving() invariant).
     *
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
