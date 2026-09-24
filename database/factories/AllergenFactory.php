<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Allergen;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-support factory only — no production seed data lives here, see
 * database/seeders/AllergenSeeder.php for the real 14 EU-mandated allergens.
 * The model has no HasFactory trait (it has no production use for one), so this factory is
 * used directly as `AllergenFactory::new()` rather than `Allergen::factory()`.
 *
 * @extends Factory<Allergen>
 */
class AllergenFactory extends Factory
{
    /** @var class-string<Allergen> */
    protected $model = Allergen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'code' => Str::slug($label, '_'),
            'label_fr' => ucfirst($label),
        ];
    }
}
