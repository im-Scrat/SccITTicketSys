<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiFailurePattern;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiFailurePattern>
 */
class AiFailurePatternFactory extends Factory
{
    protected $model = AiFailurePattern::class;

    public function definition(): array
    {
        return [
            'pc_unit_id' => PcUnit::factory(),
            'pattern_name' => fake()->words(3, true),
            'detected_problem' => fake()->sentence(),
            'occurrence_count' => fake()->numberBetween(1, 20),
            'average_days_between_failures' => fake()->numberBetween(7, 180),
            'confidence' => fake()->randomFloat(4, 0, 1),
            'last_detected' => now(),
        ];
    }
}
