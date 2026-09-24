<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lookup entry for one of the ~20 fixed ingredient categories (e.g. "Fruits", "Viandes").
 * `code` is the stable identity to compare/join on; `label_fr` and `description_fr` are
 * display-only.
 *
 * @property int $id
 * @property string $code
 * @property string $label_fr
 * @property string $description_fr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IngredientCategory extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'label_fr', 'description_fr'];

    /**
     * @return HasMany<Ingredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'category_id');
    }
}
