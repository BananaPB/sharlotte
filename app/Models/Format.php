<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lookup entry for one of the closed, read-only packaging/shape words (e.g. "tranche",
 * "bouteille") that a Unit gives a gram weight to. `code` is the stable identity; the
 * labels are display-only.
 *
 * Two explicit labels on purpose: display picks singular/plural by quantity (French:
 * plural from 2), and plurals are never computed in code.
 *
 * @property int $id
 * @property string $code
 * @property string $label_fr
 * @property string $label_fr_plural
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Format extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'label_fr', 'label_fr_plural'];

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
