<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AiKnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A verified technical article (SRS FR-AI-005). Only `published` articles are
 * ever embedded or retrieved; the observer in the KnowledgeBase domain keeps the
 * vector index in step with every save, delete and restore.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string $slug
 * @property string|null $category
 * @property string|null $problem_signature
 * @property string|null $root_cause
 * @property string|null $verified_solution
 * @property int $verification_count
 * @property KnowledgeStatus $status
 * @property int|null $created_from_ticket_id
 * @property int|null $created_by
 * @property Carbon|null $published_at
 */
class AiKnowledgeArticle extends Model
{
    /** @use HasFactory<AiKnowledgeArticleFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $table = 'ai_knowledge_articles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function createdFromTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'created_from_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
