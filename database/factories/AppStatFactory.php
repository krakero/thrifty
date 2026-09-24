<?php

namespace Database\Factories;

use App\Models\AppStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppStat>
 */
class AppStatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frames_processed' => 0,
            'items_identified' => 0,
            'searches_performed' => 0,
            'model_calls' => 0,
            'last_updated' => null,
        ];
    }
}
