<?php

namespace Database\Factories;

use App\Enums\ValuationSourceType;
use App\Models\Item;
use App\Models\ValuationSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValuationSource>
 */
class ValuationSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'source_type' => ValuationSourceType::Retail,
            'title' => fake()->sentence(4),
            'url' => fake()->url(),
            'price_cents' => fake()->numberBetween(500, 20000),
            'currency' => 'USD',
            'captured_at' => now(),
        ];
    }
}
