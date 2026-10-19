<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TicketStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One ticket lifecycle transition (SRS FR-TKT-005). Append-only — `UPDATED_AT`
 * is disabled because a history row is never edited, only added.
 *
 * `from_status_id` is null on the opening row, and `changed_by` is null when the
 * scheduled auto-close performed the transition rather than a person.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $from_status_id
 * @property int $to_status_id
 * @property int|null $changed_by
 * @property string|null $remarks
 * @property Carbon|null $created_at
 */
class TicketStatusHistory extends Model
{
    /** @use HasFactory<TicketStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ticket_status_history';

    protected $guarded = ['id'];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketStatus, $this> */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'from_status_id');
    }

    /** @return BelongsTo<TicketStatus, $this> */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'to_status_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
