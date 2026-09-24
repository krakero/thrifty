<?php

namespace Database\Factories;

use App\Enums\FrameRunStatus;
use App\Models\FrameRun;
use App\Models\ScanSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FrameRun>
 */
class FrameRunFactory extends Factory
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
            'frame_path' => 'frames/'.fake()->uuid().'.jpg',
            'captured_at' => now(),
            'completed_at' => now(),
            'latency_ms' => fake()->numberBetween(2000, 30000),
            'item_count' => 0,
            'model_calls' => 1,
            'searches_performed' => 0,
            'model' => 'gpt-5.6-luna',
            'instructions' => 'Inspect a single frame.',
            'input_json' => [],
            'events_json' => [],
            'raw_responses_json' => [],
            'output_json' => ['items' => []],
            'usage_json' => [],
            'status' => FrameRunStatus::Completed,
            'error' => null,
        ];
    }
}
