<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IngredientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-support factory only — no production seed data lives here, see
 * database/seeders/IngredientCategorySeeder.php for the real ~20 fixed categories.
 * The model has no HasFactory trait (it has no production use for one), so this factory is
 * used directly as `IngredientCategoryFactory::new()` rather than `IngredientCategory::factory()`.
 *
 * @extends Factory<IngredientCategory>
 */
class IngredientCategoryFactory extends Factory
{
    /** @var class-string<IngredientCategory> */
    protected $model = IngredientCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words($nb, asText: true) always returns a string, but PHPStan types the return as
        // array|string since it can't narrow on a literal boolean argument. A (string) cast
        // would silence PHPStan's array|string=>string check but trips its separate
        // array-to-string cast warning instead, so narrow via @var here.
        /** @var string $label */
        $label = fake()->unique()->words(3, true);

        return [
            'code' => Str::slug($label, '_'),
            'label_fr' => ucfirst($label),
        ];
    }
}
