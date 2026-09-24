<?php

namespace Database\Factories;

use App\Enums\ScanSource;
use App\Models\ScanSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanSession>
 */
class ScanSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => ScanSource::Camera,
            'source_name' => 'Back camera',
            'started_at' => now(),
            'ended_at' => null,
        ];
    }
}
