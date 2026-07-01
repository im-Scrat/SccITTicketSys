<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmbeddableSourceType;
use App\Models\AiEmbedding;
use App\Models\AiModel;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiEmbedding>
 */
class AiEmbeddingFactory extends Factory
{
    protected $model = AiEmbedding::class;

    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'embeddable_type' => EmbeddableSourceType::Ticket->value,
            'embeddable_id' => Ticket::factory(),
            'ai_model_id' => AiModel::factory()->embedding(),
            'chunk_index' => 0,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'embedding' => $this->randomVector(768),
            'token_count' => fake()->numberBetween(10, 500),
        ];
    }

    /**
     * Build a pgvector literal of the given dimension, e.g. "[0.12,0.98,...]".
     */
    private function randomVector(int $dimensions): string
    {
        $values = array_map(
            static fn (): string => (string) round(mt_rand() / mt_getrandmax(), 6),
            range(1, $dimensions),
        );

        return '['.implode(',', $values).']';
    }
}
