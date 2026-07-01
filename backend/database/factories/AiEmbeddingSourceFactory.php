<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmbeddableSourceType;
use App\Enums\EmbeddingStatus;
use App\Models\AiEmbeddingSource;
use App\Models\AiModel;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiEmbeddingSource>
 */
class AiEmbeddingSourceFactory extends Factory
{
    protected $model = AiEmbeddingSource::class;

    public function definition(): array
    {
        return [
            'source_type' => EmbeddableSourceType::Ticket->value,
            'source_id' => Ticket::factory(),
            'ai_model_id' => AiModel::factory()->embedding(),
            'embedding_status' => EmbeddingStatus::Pending->value,
            'chunk_count' => 0,
        ];
    }
}
