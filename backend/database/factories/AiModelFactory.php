<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiModality;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    protected $model = AiModel::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'provider' => 'gemini',
            'model_identifier' => 'gemini-'.fake()->randomElement(['1.5-flash', '1.5-pro', '2.0-flash']),
            'version' => fake()->semver(),
            'modality' => AiModality::Text->value,
            'embedding_dimensions' => null,
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function embedding(): static
    {
        return $this->state(fn (array $attributes) => [
            'modality' => AiModality::Embedding->value,
            'model_identifier' => 'text-embedding-004',
            'embedding_dimensions' => 768,
        ]);
    }
}
