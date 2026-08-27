<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketSource;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $ticket_number
 * @property int $reporter_id
 * @property int|null $assigned_technician_id
 * @property int|null $room_id
 * @property int|null $pc_unit_id
 * @property int $category_id
 * @property int $priority_id
 * @property int $current_status_id
 * @property int|null $duplicate_of_id
 * @property string $title
 * @property string $description
 * @property TicketSource $source
 * @property string|null $ai_summary
 * @property string|null $ai_confidence
 * @property int|null $estimated_resolution_minutes
 * @property bool $technician_required
 * @property int $upvote_count
 * @property int $comment_count
 * @property int $attachment_count
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $reopened_at
 * @property Carbon|null $response_due_at
 * @property Carbon|null $resolution_due_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * Transient, request-scoped attributes. Not columns — they are attached to the
 * models of one page before serialization so the work happens once per request
 * instead of once per row:
 * @property bool|null $has_voted set by the controllers from a single
 *                                `whereIn` over the page (FR-TKT-009)
 * @property array<string, mixed>|null $sla_posture
 *                                                  set from SlaCalculator so a 20-row
 *                                                  page is measured against one `now()`
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, HasUuidRouteKey, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => TicketSource::class,
            'ai_confidence' => 'decimal:4',
            'technician_required' => 'boolean',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
        ];
    }

    /**
     * Where this ticket actually is.
     *
     * A ticket can name a room directly, or inherit one from the PC unit it was
     * reported against — a machine already knows where it lives, so a reporter
     * who picks a PC does not have to name the room as well. The PC's room wins
     * when both are present, because the equipment is the more specific answer.
     *
     * Resolved here rather than in each of the three ticket projections, which
     * would otherwise each carry a copy of the same rule.
     */
    public function locationRoom(): ?Room
    {
        $pcUnit = $this->getRelationValue('pcUnit');

        if ($pcUnit instanceof PcUnit) {
            $room = $pcUnit->getRelationValue('room');

            if ($room instanceof Room) {
                return $room;
            }
        }

        $own = $this->getRelationValue('room');

        return $own instanceof Room ? $own : null;
    }

    /**
     * The status row, or null if it could not be resolved.
     *
     * `current_status_id` is NOT NULL, so the relation is typed non-null — but a
     * lookup row can be missing from a partially-seeded database, and every
     * projection reads several fields off it. This keeps that one check in one
     * place instead of a nullsafe chain per field.
     */
    public function statusRow(): ?TicketStatus
    {
        $status = $this->getRelationValue('status');

        return $status instanceof TicketStatus ? $status : null;
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<TicketCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    /** @return BelongsTo<TicketPriority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id');
    }

    /** @return BelongsTo<TicketStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'current_status_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'duplicate_of_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_tags');
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /** @return HasMany<TicketUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(TicketUpdate::class);
    }

    /** @return HasMany<TicketStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class);
    }

    /** @return HasMany<TicketVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(TicketVote::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<TechnicianAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TechnicianAssignment::class);
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }
}
