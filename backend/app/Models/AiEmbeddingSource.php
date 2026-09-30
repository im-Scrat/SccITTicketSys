<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\KnowledgeBase\Services\KnowledgeIndexer;
use App\Enums\EmbeddableSourceType;
use App\Enums\EmbeddingStatus;
use Database\Factories\AiEmbeddingSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The index's record of one source and the state of its vectors (WP-P; SRS
 * FR-AI-006) — one row per source and embedding model, whatever number of
 * chunks it has. The chunks themselves are `ai_embeddings` rows.
 *
 * Only {@see KnowledgeIndexer} changes `embedding_status`: pending → processing
 * → indexed | failed, and indexed → stale when the content moves on. `content_hash`
 * is the hash of what is *in the index*, so comparing it with the article's
 * current hash is the staleness test. `last_error` only ever holds one of the
 * codebase's own static messages.
 *
 * @property int $id
 * @property EmbeddableSourceType $source_type
 * @property int $source_id
 * @property int $ai_model_id
 * @property EmbeddingStatus $embedding_status
 * @property int $chunk_count
 * @property string|null $content_hash
 * @property Carbon|null $indexed_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiEmbeddingSource extends Model
{
    /** @use HasFactory<AiEmbeddingSourceFactory> */
    use HasFactory;

    protected $table = 'ai_embedding_sources';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_type' => EmbeddableSourceType::class,
            'embedding_status' => EmbeddingStatus::class,
            'indexed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiModel, $this> */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
