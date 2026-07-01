<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiFeedback;
use App\Models\AiRecommendation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiFeedback>
 */
class AiFeedbackFactory extends Factory
{
    protected $model = AiFeedback::class;

    public function definition(): array
    {
        return [
            'ai_recommendation_id' => AiRecommendation::factory(),
            'user_id' => User::factory(),
            'was_helpful' => fake()->boolean(),
            'rating' => fake()->numberBetween(1, 5),
            'feedback' => fake()->optional()->sentence(),
        ];
    }
}
