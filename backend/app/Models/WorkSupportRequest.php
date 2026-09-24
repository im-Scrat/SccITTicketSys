<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkSupportStatus;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\WorkSupportRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A technician's request for help finishing a job (SRS FR-WSR-001..014;
 * SDD DD-51, DD-54).
 *
 * **Not a procurement request.** `procurement_requests` models a purchasing
 * lifecycle and carries no link to a PC unit, ticket or maintenance record. This
 * is a technician standing at a machine asking for what the job needs, and its
 * decisions include *reschedule* and *discuss in person* — states purchasing
 * does not have (Client decision, SRS OI-09). `procurement_request_id` is the
 * one place the two genuinely meet (FR-WSR-013), and it stays null until
 * fulfilment actually requires purchasing.
 *
 * ── No soft delete, and no status setter ──────────────────────────────────
 *
 * A request is a record of something a technician asked for and an
 * administrator decided. Archiving it would let the decision disappear while
 * the maintenance record that depended on it stayed. Withdrawal is a *status*
 * (`cancelled`, FR-WSR-014), which is the honest way to say "no longer needed"
 * without erasing that it was asked.
 *
 * `status` is never assigned from a controller. Every move goes through
 * `WorkSupportRequestLifecycle`, whose transition map is the only thing that
 * may write this column (FR-WSR-004, DD-54).
 *
 * @property int $id
 * @property string $uuid
 * @property int $pc_unit_id
 * @property int|null $ticket_id
 * @property int|null $maintenance_record_id
 * @property int $technician_id
 * @property string $explanation
 * @property WorkSupportStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decline_reason
 * @property Carbon|null $rescheduled_to
 * @property string|null $reschedule_reason
 * @property Carbon|null $acknowledged_at
 * @property string|null $clarification_reason
 * @property Carbon|null $proposed_meeting_at
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_note
 * @property Carbon|null $closed_at
 * @property int|null $procurement_request_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PcUnit|null $pcUnit
 * @property-read Ticket|null $ticket
 * @property-read MaintenanceRecord|null $maintenanceRecord
 * @property-read User|null $technician
 * @property-read User|null $decidedBy
 * @property-read User|null $cancelledBy
 */
class WorkSupportRequest extends Model
{
    /** @use HasFactory<WorkSupportRequestFactory> */
    use HasFactory, HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => WorkSupportStatus::class,
            'decided_at' => 'datetime',
            'rescheduled_to' => 'datetime',
            'acknowledged_at' => 'datetime',
            'proposed_meeting_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------ context */

    /**
     * The machine the request is about. `pc_unit_id` is NOT NULL and
     * `ON DELETE RESTRICT` — a request always names a machine, and that machine
     * cannot be removed while it does.
     *
     * @return BelongsTo<PcUnit, $this>
     */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /* ------------------------------------------------------------- people */

    /**
     * Who asked. The row-scope column: FR-WSR-009's "everything they personally
     * submitted" is `technician_id`, and there is deliberately no separate
     * `created_by` — a support request is always opened by the technician it
     * belongs to, so a second column would only be somewhere for the two to
     * disagree.
     *
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Which administrator approved or declined it.
     *
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Who withdrew it — the technician, or an administrator on their behalf
     * (FR-WSR-014 requires the actor to be recorded either way).
     *
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /* ------------------------------------------------------------ children */

    /** @return HasMany<WorkSupportRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WorkSupportRequestItem::class);
    }

    /** @return HasMany<WorkSupportRequestAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(WorkSupportRequestAttachment::class);
    }

    /* ------------------------------------------------------------ helpers */

    /** Is an administrator decision still outstanding? */
    public function isUndecided(): bool
    {
        return $this->status->isUndecided();
    }
}
