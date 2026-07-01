<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PredictionStatus;
use App\Models\AiPrediction;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPrediction>
 */
class AiPredictionFactory extends Factory
{
    protected $model = AiPrediction::class;

    public function definition(): array
    {
        return [
            'pc_unit_id' => PcUnit::factory(),
            'predicted_issue' => fake()->sentence(4),
            'probability' => fake()->randomFloat(4, 0, 1),
            'predicted_within_days' => fake()->numberBetween(1, 90),
            'explanation' => fake()->paragraph(),
            'status' => PredictionStatus::Pending->value,
            'generated_at' => now(),
        ];
    }
}
