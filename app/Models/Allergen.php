<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A lookup entry for one of the 14 EU-mandated allergens (Regulation 1169/2011, Annex II).
 * `code` is the stable identity Phase 2's allergen union/deduplication logic compares on;
 * `label_fr` is display-only.
 *
 * @property int $id
 * @property string $code
 * @property string $label_fr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Allergen extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'label_fr'];

    /**
     * Ingredients that definitely contain this allergen.
     *
     * @return BelongsToMany<Ingredient, $this>
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_allergen')->withTimestamps();
    }

    /**
     * Ingredients that may contain traces of this allergen.
     *
     * @return BelongsToMany<Ingredient, $this>
     */
    public function ingredientTraces(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_allergen_trace')->withTimestamps();
    }
}
