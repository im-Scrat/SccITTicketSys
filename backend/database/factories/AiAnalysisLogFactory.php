<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiSeverity;
use App\Models\AiAnalysisLog;
use App\Models\AiModel;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAnalysisLog>
 */
class AiAnalysisLogFactory extends Factory
{
    protected $model = AiAnalysisLog::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'ai_model_id' => AiModel::factory(),
            'analyzed_at' => now(),
            'confidence_score' => fake()->randomFloat(4, 0, 1),
            'problem_category' => fake()->word(),
            'severity' => fake()->randomElement(AiSeverity::values()),
            'estimated_resolution_minutes' => fake()->numberBetween(15, 480),
            'technician_required' => fake()->boolean(),
            'summary' => fake()->paragraph(),
            'prompt_tokens' => fake()->numberBetween(100, 2000),
            'completion_tokens' => fake()->numberBetween(50, 1000),
            'latency_ms' => fake()->numberBetween(200, 5000),
        ];
    }
}
