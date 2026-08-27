<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\TicketCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A comment on a ticket (SRS FR-TKT-007).
 *
 * `is_internal` notes are visible only to Technicians and Administrators, and
 * are excluded **in the query** rather than hidden in the resource — an internal
 * note must never enter a requester's result set in the first place.
 *
 * `edited_by`/`deleted_by` (Phase 2.6) record the moderator when an
 * Administrator edits or removes someone else's comment, so the row explains its
 * own state without a trip to the audit log.
 *
 * @property int $id
 * @property string $uuid
 * @property int $ticket_id
 * @property int $user_id
 * @property int|null $parent_comment_id
 * @property string $body
 * @property bool $is_internal
 * @property bool $is_edited
 * @property Carbon|null $edited_at
 * @property int|null $edited_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TicketComment extends Model
{
    /** @use HasFactory<TicketCommentFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_edited' => 'boolean',
            'edited_at' => 'datetime',
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

    /** @return BelongsTo<TicketComment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketComment::class, 'parent_comment_id');
    }

    /** @return HasMany<TicketComment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'parent_comment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
