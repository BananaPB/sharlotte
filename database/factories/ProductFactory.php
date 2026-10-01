<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-support factory only. The model has no HasFactory trait (it has no production use
 * for one), so this factory is used directly as `ProductFactory::new()` rather than
 * `Product::factory()`.
 *
 * Defaults to a product owned by a freshly-created user (products are always owned,
 * docs/decisions.md entry 10).
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @var class-string<Product> */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // words($nb, asText: true) always returns a string, but PHPStan types the return as
        // array|string, so narrow via @var (see IngredientCategoryFactory for why not a cast).
        /** @var string $name */
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            // Resolved by Eloquent into a newly-created user's id (see IngredientFactory).
            'owner_id' => UserFactory::new(),
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (): array => ['owner_id' => $owner->id]);
    }
}
