<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmbeddableSourceType;
use App\Enums\EmbeddingStatus;
use Database\Factories\AiEmbeddingSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
