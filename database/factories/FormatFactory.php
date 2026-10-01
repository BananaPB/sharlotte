<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Format;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test-support factory only — no production seed data lives here, see
 * database/seeders/FormatSeeder.php for the real closed list of formats.
 * The model has no HasFactory trait (it has no production use for one), so this factory is
 * used directly as `FormatFactory::new()` rather than `Format::factory()`.
 *
 * @extends Factory<Format>
 */
class FormatFactory extends Factory
{
    /** @var class-string<Format> */
    protected $model = Format::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words($nb, asText: true) always returns a string, but PHPStan types the return as
        // array|string since it can't narrow on a literal boolean argument, so narrow via
        // @var here (see IngredientCategoryFactory for why not a (string) cast).
        /** @var string $label */
        $label = fake()->unique()->words(2, true);

        return [
            'code' => Str::slug($label, '_'),
            'label_fr' => $label,
            'label_fr_plural' => $label.'s',
        ];
    }
}
