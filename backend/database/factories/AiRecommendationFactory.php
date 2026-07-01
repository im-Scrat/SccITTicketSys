<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiAnalysisLog;
use App\Models\AiRecommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRecommendation>
 */
class AiRecommendationFactory extends Factory
{
    protected $model = AiRecommendation::class;

    public function definition(): array
    {
        return [
            'ai_analysis_log_id' => AiAnalysisLog::factory(),
            'step_order' => fake()->numberBetween(1, 5),
            'recommendation' => fake()->sentence(),
            'is_completed' => false,
        ];
    }
}
