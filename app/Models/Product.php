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
 * The finished item, top of the Product > Preparation > Ingredient tree
 * (docs/domain-model.md). Never used as a component: recipe_lines has no column that could
 * point to a product. Kept as its own table rather than merged with preparations so it can
 * gain product-only fields later (docs/decisions.md entry 8). Always owned by a user
 * (docs/decisions.md entry 10).
 *
 * @property int $id
 * @property string $name
 * @property int $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Collection<int, RecipeLine> $recipeLines
 */
class Product extends Model implements HasRecipe
{
    // `owner_id` is deliberately not fillable: ownership is always set explicitly from the
    // authenticated user ($product->owner()->associate($user)), never mass-assigned.
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
     * @return HasMany<RecipeLine, $this>
     */
    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class)
            ->orderBy('position')
            ->orderBy('id');
    }
}
