<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiSender;
use Database\Factories\AiConversationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $conversation_id
 * @property int|null $ticket_id
 * @property int|null $user_id
 * @property int|null $ai_model_id
 * @property AiSender $sender
 * @property string $message
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $latency_ms
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
class AiConversationLog extends Model
{
    /** @use HasFactory<AiConversationLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_conversation_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sender' => AiSender::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AiModel, $this> */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
