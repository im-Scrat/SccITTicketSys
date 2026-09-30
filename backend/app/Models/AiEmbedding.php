<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmbeddableSourceType;
use Database\Factories\AiEmbeddingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One embedded chunk of a source — a slice of text and its 768-dimension vector
 * (WP-P). Written by `GenerateEmbeddingJob` in bulk, never through this model,
 * because the pgvector column has no Eloquent cast; this class exists for
 * relationships and factories.
 *
 * Only `knowledge_article` rows are ever written. The enum also names the
 * transactional sources for the schema's sake, but they are deliberately not
 * indexed — see `KnowledgeIndexer`.
 *
 * @property int $id
 * @property EmbeddableSourceType $embeddable_type
 * @property int $embeddable_id
 * @property int $ai_model_id
 * @property int $chunk_index
 * @property string $content
 * @property string $content_hash
 * @property int|null $token_count
 * @property array<string, mixed>|null $metadata
 */
class AiEmbedding extends Model
{
    /** @use HasFactory<AiEmbeddingFactory> */
    use HasFactory;

    protected $table = 'ai_embeddings';

    protected $guarded = ['id'];

    /**
     * The raw pgvector value is large; keep it out of array/JSON output.
     *
     * @var list<string>
     */
    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'embeddable_type' => EmbeddableSourceType::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<AiModel, $this> */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
