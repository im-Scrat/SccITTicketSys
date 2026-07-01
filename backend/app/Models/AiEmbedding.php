<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmbeddableSourceType;
use Database\Factories\AiEmbeddingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
