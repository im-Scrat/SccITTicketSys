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
