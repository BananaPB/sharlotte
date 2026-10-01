<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientPrivacy;
use App\Enums\IngredientStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A raw, bought-as-is item with no recipe of its own — the leaf of the
 * Product > Preparation > Ingredient tree described in docs/domain-model.md. Carries its
 * own nutrition (per 100g) and allergens directly.
 *
 * The seven core nutrition values (calories, fats, saturates, carbohydrates, sugars,
 * proteins, salt) are mandatory (docs/decisions.md entry 9). `fibers` and `water` stay
 * nullable: null means "unknown data point", which is different from 0 ("measured at zero
 * grams").
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $owner_id
 * @property string $name
 * @property string $slug
 * @property IngredientStorage $storage
 * @property IngredientPrivacy $privacy
 * @property string $calories
 * @property string $fats
 * @property string $saturates
 * @property string $carbohydrates
 * @property string $sugars
 * @property string|null $fibers
 * @property string $proteins
 * @property string $salt
 * @property string|null $water
 * @property bool $to_review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IngredientCategory $category
 * @property-read User|null $owner
 * @property-read Collection<int, Unit> $units
 */
class Ingredient extends Model
{
    // `owner_id` is deliberately not fillable: ownership is always set explicitly from the
    // authenticated user ($ingredient->owner()->associate($user)), never mass-assigned, so
    // request input can never choose the owner (and, through it, the privacy).
    /** @var list<string> */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'storage',
        'calories',
        'fats',
        'saturates',
        'carbohydrates',
        'sugars',
        'fibers',
        'proteins',
        'salt',
        'water',
        'to_review',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storage' => IngredientStorage::class,
            'privacy' => IngredientPrivacy::class,
            // decimal casts return strings, not floats: nutrition math needs exact decimal
            // arithmetic (see docs/decisions.md #5), not float rounding error.
            'calories' => 'decimal:2',
            'fats' => 'decimal:2',
            'saturates' => 'decimal:2',
            'carbohydrates' => 'decimal:2',
            'sugars' => 'decimal:2',
            'fibers' => 'decimal:2',
            'proteins' => 'decimal:2',
            'salt' => 'decimal:2',
            'water' => 'decimal:2',
            'to_review' => 'boolean',
        ];
    }

    /**
     * Keep `privacy` in lockstep with `owner_id`'s nullability (null = public/admin-owned)
     * so the two columns can never drift apart. `privacy` exists as its own stored,
     * queryable column — rather than only a computed accessor — because both the source
     * spreadsheet and docs/domain-model.md name it as a first-class concept; deriving and
     * persisting it here (instead of trusting callers to set it) is what keeps that
     * explicit column safe to have alongside owner_id as the actual source of truth.
     */
    protected static function booted(): void
    {
        static::saving(function (Ingredient $ingredient): void {
            $ingredient->privacy = $ingredient->owner_id === null
                ? IngredientPrivacy::Public
                : IngredientPrivacy::Private;
        });
    }

    /**
     * @return BelongsTo<IngredientCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(IngredientCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Allergens this ingredient definitely contains.
     *
     * @return BelongsToMany<Allergen, $this>
     */
    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'ingredient_allergen')->withTimestamps();
    }

    /**
     * Allergens this ingredient may contain traces of.
     *
     * @return BelongsToMany<Allergen, $this>
     */
    public function allergenTraces(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'ingredient_allergen_trace')->withTimestamps();
    }

    /**
     * Gram weights for this ingredient's formats (e.g. "1 tranche = 40 g"), public and
     * private alike — filter with Unit::scopeVisibleTo() when showing them to a user.
     *
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * Build the `name + storage + owner_id` slug that is this ingredient's uniqueness key.
     * The same name can legitimately exist multiple times (different storage state, or the
     * same name owned by different users) — the slug disambiguates those cases.
     */
    public static function buildSlug(string $name, IngredientStorage $storage, ?int $ownerId): string
    {
        $slug = Str::slug($name).'-'.$storage->value;

        return $ownerId === null ? $slug : "{$slug}-u{$ownerId}";
    }
}
