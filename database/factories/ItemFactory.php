<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ScanSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_session_id' => ScanSession::factory(),
            'frame_run_id' => null,
            'fingerprint' => fake()->unique()->words(4, true),
            'name' => ucfirst(fake()->words(3, true)),
            'category' => fake()->randomElement(['Furniture', 'Electronics', 'Kitchen', 'Toys', 'Clothing', 'Media']),
            'brand' => fake()->optional()->company(),
            'model' => null,
            'description' => fake()->sentence(),
            'condition' => fake()->randomElement(['Good', 'Fair', 'Like new']),
            'confidence' => fake()->randomFloat(2, 0.7, 0.99),
            'observed_price_cents' => fake()->optional()->numberBetween(100, 5000),
            'currency' => 'USD',
            'estimated_low_cents' => $low = fake()->numberBetween(500, 5000),
            'estimated_high_cents' => $low + fake()->numberBetween(500, 5000),
            'retail_price_cents' => fake()->optional()->numberBetween(2000, 20000),
            'active_price_cents' => null,
            'sold_price_cents' => null,
            'value_summary' => fake()->sentence(),
            'thumbnail_path' => 'frames/'.fake()->uuid().'.jpg',
            'box_x_min' => 100,
            'box_y_min' => 120,
            'box_x_max' => 600,
            'box_y_max' => 800,
            'raw_json' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'seen_count' => 1,
        ];
    }
}
