<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KnowledgeStatus;
use App\Models\AiKnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiKnowledgeArticle>
 */
class AiKnowledgeArticleFactory extends Factory
{
    protected $model = AiKnowledgeArticle::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(5);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 100000),
            'category' => fake()->word(),
            'problem_signature' => fake()->sentence(),
            'root_cause' => fake()->paragraph(),
            'verified_solution' => fake()->paragraph(),
            'verification_count' => fake()->numberBetween(0, 25),
            'status' => KnowledgeStatus::Draft->value,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeStatus::Published->value,
            'published_at' => now(),
        ]);
    }
}
