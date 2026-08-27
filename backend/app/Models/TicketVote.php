<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One upvote (SRS FR-TKT-009). `UNIQUE(ticket_id, user_id)` makes "at most once
 * per user" a database guarantee, and an `AFTER INSERT OR DELETE` trigger owns
 * `tickets.upvote_count` — the application never writes that column.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property Carbon|null $created_at
 */
class TicketVote extends Model
{
    /** @use HasFactory<TicketVoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

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
}
