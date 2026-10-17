<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Events\TicketStatusChanged;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Enums\TicketUpdateType;
use App\Models\SystemSetting;
use App\Models\TechnicianAssignment;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\TicketStatusHistory;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for a ticket's status (SRS FR-TKT-005/006/016).
 *
 * Modelled on `AssetLifecycle`, with one structural difference: asset statuses
 * are a PHP enum, but ticket statuses are **rows** in `ticket_statuses`. So the
 * map here is keyed on **slug**, and the open/terminal semantics are read from
 * the row rather than hard-coded — an Administrator adding a status later does
 * not change what "terminal" means to this service.
 *
 * Three guarantees:
 *
 *  1. **No transition is unaudited.** The ticket update, the
 *     `ticket_status_history` row, the `ticket_updates` entry and the
 *     `activity_logs` record are written in **one transaction**. A status cannot
 *     move while leaving any of those four behind.
 *  2. **Only legal transitions happen**, and only by an actor entitled to make
 *     that particular move — the map carries *who*, not just *what*, because
 *     "resolved → closed" is legitimate for a reporter confirming and for an
 *     administrator overriding, but not for a passer-by.
 *  3. **`first_response_at` is stamped once**, the first time a technician or
 *     administrator responds, which is what makes the response SLA measurable.
 *
 * `cancelled` is absorbing. `closed` is terminal but reopenable inside the
 * configured window — the same shape as `AssetStatus::Disposed` vs `Retired`.
 */
class TicketLifecycle
{
    /** Who may perform a transition. */
    public const ACTOR_ADMIN = 'administrator';

    public const ACTOR_TECHNICIAN = 'assigned_technician';

    public const ACTOR_REPORTER = 'reporter';

    public const ACTOR_SYSTEM = 'system';

    /**
     * The transition map: `from slug => [to slug => list of entitled actors]`.
     *
     * Mirrors §6.1 of the Phase 2.6 plan exactly. `resolved` is deliberately
     * *not* terminal — it means "awaiting reporter confirmation" (FR-TKT-016),
     * which is why the seed marks it `is_open = true`.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const TRANSITIONS = [
        'open' => [
            'assigned' => [self::ACTOR_ADMIN],
            'in-progress' => [self::ACTOR_ADMIN],
            /*
             * WP-J — the reporter's FIXED outcome after trying the AI's
             * recommendations. The one deliberate departure from §6.1 of the
             * Phase 2.6 plan: that map had no path from `open` to `resolved`
             * for anyone. It lands on `resolved` rather than `closed` so the
             * existing FR-TKT-016 machinery applies unchanged — the reporter can
             * still reopen, and CloseStaleResolvedTickets still auto-closes it
             * after `tickets.auto_close_days`. Reporter-only: a technician or
             * administrator resolving a ticket goes through `in-progress` as
             * before. The AI itself is never an actor here — only a human
             * reporter's explicit request reaches this row.
             */
            'resolved' => [self::ACTOR_REPORTER],
            'cancelled' => [self::ACTOR_ADMIN, self::ACTOR_REPORTER],
            'closed' => [self::ACTOR_ADMIN],
        ],
        'assigned' => [
            'in-progress' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'on-hold' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'open' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'cancelled' => [self::ACTOR_ADMIN],
        ],
        'in-progress' => [
            'on-hold' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'resolved' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'assigned' => [self::ACTOR_ADMIN],
            'cancelled' => [self::ACTOR_ADMIN],
        ],
        'on-hold' => [
            'in-progress' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'assigned' => [self::ACTOR_ADMIN],
            'cancelled' => [self::ACTOR_ADMIN],
        ],
        'resolved' => [
            'closed' => [self::ACTOR_ADMIN, self::ACTOR_REPORTER, self::ACTOR_SYSTEM],
            'in-progress' => [self::ACTOR_ADMIN, self::ACTOR_TECHNICIAN],
            'open' => [self::ACTOR_ADMIN, self::ACTOR_REPORTER],
        ],
        'closed' => [
            'open' => [self::ACTOR_ADMIN, self::ACTOR_REPORTER],
        ],
        // Absorbing: a cancelled ticket has been withdrawn, not paused.
        'cancelled' => [],
    ];

    /*
     * No `SlaCalculator` here, deliberately: the deadlines move when the
     * *priority* changes, not when the status does, so recalculation belongs to
     * `UpdateTicket::changePriority()`. Injecting it here would suggest a
     * transition might silently move a clock, which it must not.
     */
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TicketVisibility $visibility,
    ) {}

    /**
     * Move a ticket to a new status, recording history and audit atomically.
     *
     * `$updateType` refines the `ticket_updates` row the same way `$action`
     * refines the audit verb — WP-J passes `AiAnalysis` for a reporter's FIXED
     * outcome, so the timeline row says *why* the ticket moved, not just that
     * it did. Omitted, it is `StatusChange`, exactly as before for every
     * existing caller.
     *
     * @throws ValidationException when the transition is illegal, the actor is
     *                             not entitled to it, or the reopen window has
     *                             closed
     */
    public function transition(
        Ticket $ticket,
        TicketStatus $target,
        ?User $actor,
        ?string $remarks = null,
        ?Request $request = null,
        ?ActivityAction $action = null,
        ?TicketUpdateType $updateType = null,
    ): Ticket {
        $ticket->loadMissing('status');
        $from = $ticket->status;

        if ($from !== null && $from->getKey() === $target->getKey()) {
            // Re-submitting the current status is a no-op, not a spurious
            // history row that would clutter the timeline.
            return $ticket;
        }

        $this->assertTransitionAllowed($ticket, $from, $target, $actor);

        DB::transaction(function () use ($ticket, $from, $target, $actor, $remarks, $updateType, $request): void {
            $ticket->forceFill([
                'current_status_id' => $target->getKey(),
                'updated_by' => $actor?->getKey(),
                ...$this->timestampsFor($ticket, $target, $actor),
            ])->save();

            TicketStatusHistory::query()->create([
                'ticket_id' => $ticket->getKey(),
                'from_status_id' => $from?->getKey(),
                'to_status_id' => $target->getKey(),
                'changed_by' => $actor?->getKey(),
                'remarks' => $remarks,
                'created_at' => now(),
            ]);

            TicketUpdate::query()->create([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $actor?->getKey(),
                'update_type' => ($updateType ?? TicketUpdateType::StatusChange)->value,
                'body' => $remarks,
                'metadata' => [
                    'from' => $from?->slug,
                    'from_label' => $from?->name,
                    'to' => $target->slug,
                    'to_label' => $target->name,
                ],
                'created_at' => now(),
            ]);

            // Inside the same transaction: a ticket must never reach a terminal
            // state while its assignment still reads as live work.
            $this->endActiveAssignment($ticket, $target, $actor, $request);
        });

        $this->audit->activity(
            $action ?? ActivityAction::TicketStatusChanged,
            actor: $actor,
            subject: $ticket,
            properties: [
                'from' => $from?->slug,
                'from_label' => $from?->name,
                'to' => $target->slug,
                'to_label' => $target->name,
                'remarks' => $remarks,
                'automated' => $actor === null,
            ],
            request: $request,
            module: 'tickets',
            description: sprintf(
                'Ticket %s: %s → %s',
                $ticket->ticket_number,
                // Null on the opening row — the ticket had no previous state.
                $from !== null ? $from->name : 'new',
                $target->name,
            ),
        );

        /*
         * WP-2.7a — the notification seam (FR-NOT-003 T2).
         *
         * Outside the transaction and after the audit row, so the event
         * describes a move that has actually committed. `$actor` is passed
         * through unchanged, including the null the scheduled auto-close
         * arrives with (FR-TKT-016): a system-driven transition still has to
         * reach the reporter, and the listener treats "nobody did this" as a
         * case rather than as a missing value.
         */
        TicketStatusChanged::dispatch($ticket, $from, $target, $actor);

        return $ticket->refresh();
    }

    /**
     * Record the opening status of a newly created ticket, so its history starts
     * at creation rather than at the first change (FR-TKT-012).
     */
    public function recordInitial(Ticket $ticket, User $actor): void
    {
        TicketStatusHistory::query()->create([
            'ticket_id' => $ticket->getKey(),
            'from_status_id' => null,
            'to_status_id' => $ticket->current_status_id,
            'changed_by' => $actor->getKey(),
            'remarks' => 'Ticket reported',
            'created_at' => now(),
        ]);
    }

    /**
     * End the ticket's active assignment because the ticket has reached a
     * terminal status (SDD DD-83).
     *
     * `canWork`, the technician's queue and every "active assignments" figure
     * read the *assignment's* status, but the generic "Move this ticket" control
     * moves only the ticket — only `RespondToAssignment` moves both. So a job
     * resolved through the generic control and then confirmed by the reporter
     * closed with its assignment still `pending`: it stayed in the technician's
     * active queue and kept the write access `canWork` grants.
     *
     * The mapping (D1):
     *
     *  - **cancelled** → the assignment is `cancelled`.
     *  - **closed** → `completed` only when the ticket was `resolved` and the
     *    latest move into `resolved` was made by *this assignment's technician*,
     *    with `completed_at` set to that move. Anyone else's resolution, or a
     *    close that skipped `resolved`, did not complete this technician's work,
     *    so it is `cancelled` — an honest record beats crediting the wrong person
     *    in the monitoring figures.
     *
     * Two deliberate omissions:
     *
     *  - **`updated_at` is left alone** (a query-builder write). Admin monitoring
     *    reads it as the technician's last activity, and closing a ticket is not
     *    one of their actions. The same reason the 2026-10-14 repair used.
     *  - **Non-terminal moves do nothing.** A `resolved` ticket keeps its
     *    assignment until it closes; the reporter may still send it back.
     *
     * It is public so `tickets:end-stale-assignments` repairs tickets stranded
     * before this existed with exactly the same rule. Callers wanting atomicity
     * with a status move call it inside that move's transaction.
     *
     * @return AssignmentStatus|null what the assignment became, null if there was
     *                               nothing to end
     */
    public function endActiveAssignment(
        Ticket $ticket,
        TicketStatus $terminal,
        ?User $actor = null,
        ?Request $request = null,
    ): ?AssignmentStatus {
        if (! $terminal->is_terminal) {
            return null;
        }

        /** @var TechnicianAssignment|null $assignment */
        $assignment = TechnicianAssignment::query()
            ->where('ticket_id', $ticket->getKey())
            ->whereIn('status', AssignmentStatus::activeValues())
            ->with('technician')
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            return null;
        }

        [$status, $completedAt] = $this->assignmentOutcome($ticket, $terminal, $assignment);

        TechnicianAssignment::query()
            ->whereKey($assignment->getKey())
            ->toBase()
            ->update($completedAt !== null
                ? ['status' => $status->value, 'completed_at' => $completedAt]
                : ['status' => $status->value]);

        $reason = match (true) {
            $terminal->slug === 'cancelled' => 'The ticket was cancelled, so the assignment ended.',
            $status === AssignmentStatus::Completed => 'The ticket was closed with this technician’s resolution, so the assignment was recorded as completed.',
            default => 'The ticket was closed without this technician’s resolution, so the assignment ended.',
        };

        // `user_id` null reads as "System" in the staff timeline; the reporter's
        // projection already omits assignment-response rows (TicketTimeline).
        TicketUpdate::query()->create([
            'ticket_id' => $ticket->getKey(),
            'user_id' => null,
            'update_type' => TicketUpdateType::Assignment->value,
            'body' => $reason,
            'metadata' => [
                'assignment_status' => $status->value,
                'technician' => $assignment->technician?->fullName(),
                'phrase' => 'assignment ended',
                'automated' => true,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        $this->audit->activity(
            ActivityAction::TicketAssignmentEnded,
            actor: $actor,
            subject: $ticket,
            properties: [
                'ticket_number' => $ticket->ticket_number,
                'assignment_status' => $status->value,
                'ticket_status' => $terminal->slug,
                'reason' => $reason,
                'automated' => true,
            ],
            request: $request,
            module: 'tickets',
            description: sprintf('Assignment on %s ended: %s', $ticket->ticket_number, $status->value),
        );

        return $status;
    }

    /**
     * What an active assignment becomes when its ticket reaches `$terminal`
     * (D1) — a pure read, so `tickets:end-stale-assignments --dry-run` can
     * report the outcome without writing anything.
     *
     * Read from `ticket_status_history`, the one record of who moved what: the
     * latest move into `closed` must have come from `resolved`, and the latest
     * move into `resolved` before it must have been made by the assignee.
     *
     * @return array{0: AssignmentStatus, 1: Carbon|null} the new status and, for
     *                                                    `completed`, the time of the resolution move
     */
    public function assignmentOutcome(
        Ticket $ticket,
        TicketStatus $terminal,
        TechnicianAssignment $assignment,
    ): array {
        $cancelled = [AssignmentStatus::Cancelled, null];

        if ($terminal->slug !== 'closed') {
            return $cancelled;
        }

        $resolvedId = TicketStatus::query()->where('slug', 'resolved')->value('id');

        $closing = TicketStatusHistory::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('to_status_id', $terminal->getKey())
            ->latest('id')
            ->first();

        if ($resolvedId === null || $closing === null || $closing->from_status_id !== $resolvedId) {
            return $cancelled;
        }

        $resolution = TicketStatusHistory::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('to_status_id', $resolvedId)
            ->where('id', '<', $closing->getKey())
            ->latest('id')
            ->first();

        return $resolution !== null && $resolution->changed_by === $assignment->technician_id
            ? [AssignmentStatus::Completed, $resolution->created_at]
            : $cancelled;
    }

    /**
     * The statuses this actor may move this ticket to right now — surfaced on
     * the detail endpoint so the client offers only legal choices instead of
     * discovering them by trial and error.
     *
     * @return list<array{value: string, label: string, color: string, terminal: bool}>
     */
    public function availableTransitions(Ticket $ticket, User $actor): array
    {
        $ticket->loadMissing('status');
        $from = $ticket->status?->slug;

        if ($from === null) {
            return [];
        }

        $role = $this->actorRole($ticket, $actor);
        $allowed = [];

        foreach (self::TRANSITIONS[$from] ?? [] as $slug => $actors) {
            if ($role !== null && in_array($role, $actors, true)) {
                $allowed[] = $slug;
            }
        }

        if ($allowed === []) {
            return [];
        }

        return TicketStatus::query()
            ->whereIn('slug', $allowed)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TicketStatus $status): array => [
                'value' => $status->slug,
                'label' => $status->name,
                'color' => $status->color,
                'terminal' => $status->is_terminal,
            ])
            ->all();
    }

    /** Is this ticket still inside its reopen window (FR-TKT-016)? */
    public function withinReopenWindow(Ticket $ticket): bool
    {
        if ($ticket->closed_at === null) {
            // Resolved-but-not-closed has no window to expire.
            return true;
        }

        return $ticket->closed_at->addDays($this->reopenWindowDays())->isFuture();
    }

    public function reopenWindowDays(): int
    {
        return max(1, (int) ($this->setting('tickets.reopen_window_days') ?? 7));
    }

    public function autoCloseDays(): int
    {
        return max(1, (int) ($this->setting('tickets.auto_close_days') ?? 14));
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @throws ValidationException
     */
    private function assertTransitionAllowed(
        Ticket $ticket,
        ?TicketStatus $from,
        TicketStatus $target,
        ?User $actor,
    ): void {
        $fromSlug = $from !== null ? $from->slug : '';
        $permitted = self::TRANSITIONS[$fromSlug][$target->slug] ?? null;

        if ($permitted === null) {
            $reachable = array_keys(self::TRANSITIONS[$fromSlug] ?? []);

            $labels = TicketStatus::query()
                ->whereIn('slug', $reachable)
                ->orderBy('sort_order')
                ->pluck('name')
                ->all();

            $fromLabel = $from !== null ? $from->name : null;

            throw ValidationException::withMessages([
                'status' => $labels === []
                    ? sprintf('%s is a final state — this ticket can no longer change status.', $fromLabel ?? 'This')
                    : sprintf(
                        'From %s a ticket can move to: %s. "%s" is not one of them.',
                        $fromLabel ?? 'its current state',
                        implode(', ', $labels),
                        $target->name,
                    ),
            ]);
        }

        $role = $this->actorRole($ticket, $actor);

        if ($role === null || ! in_array($role, $permitted, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'You are not able to move this ticket to %s.',
                    $target->name,
                ),
            ]);
        }

        // The reopen window is a question of timing, not entitlement — the user
        // is allowed to reopen, they are simply too late.
        if ($from?->slug === 'closed' && $target->slug === 'open'
            && $role === self::ACTOR_REPORTER
            && ! $this->withinReopenWindow($ticket)
        ) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'This ticket was closed more than %d days ago and can no longer be reopened. Report a new issue, or ask an administrator to reopen it.',
                    $this->reopenWindowDays(),
                ),
            ]);
        }
    }

    /**
     * Which role is this actor playing *for this ticket*?
     *
     * Deliberately ticket-relative: the same technician is
     * `assigned_technician` on their own job and nothing at all on someone
     * else's. A null actor is the scheduler performing an auto-close.
     */
    private function actorRole(Ticket $ticket, ?User $actor): ?string
    {
        if ($actor === null) {
            return self::ACTOR_SYSTEM;
        }

        if ($actor->role?->slug === 'administrator' && $actor->hasPermissionTo('tickets.update')) {
            return self::ACTOR_ADMIN;
        }

        if ($this->visibility->canWork($actor, $ticket)) {
            return self::ACTOR_TECHNICIAN;
        }

        if ($ticket->reporter_id === $actor->getKey()) {
            return self::ACTOR_REPORTER;
        }

        return null;
    }

    /**
     * The lifecycle timestamps this transition stamps (FR-TKT-006).
     *
     * @return array<string, mixed>
     */
    private function timestampsFor(Ticket $ticket, TicketStatus $target, ?User $actor): array
    {
        $stamps = [];
        $now = now();

        if ($target->slug === 'resolved') {
            $stamps['resolved_at'] = $now;
        }

        if ($target->slug === 'closed') {
            $stamps['closed_at'] = $now;
        }

        // Sent back to work. `resolved → in-progress` is a legal move for an
        // administrator or the assigned technician, and a ticket that leaves
        // `resolved` for anything but `closed` is no longer resolved. Leaving the
        // stamp makes every `resolved_at` reader disagree with the ticket's own
        // status: the dashboard's "awaiting confirmation" and "resolved in 30
        // days" count it while the status-based lists they link to do not, and
        // the SLA clock treats a ticket being re-worked as finished. Resolving it
        // again stamps a fresh time; the earlier resolution stays in the history.
        if ($ticket->status?->slug === 'resolved' && ! in_array($target->slug, ['resolved', 'closed'], true)) {
            $stamps['resolved_at'] = null;
        }

        // Reopening clears the terminal stamps: a ticket that is open again has
        // not been resolved, and leaving `resolved_at` set would make the
        // auto-close sweep pick it up a second time.
        if ($target->slug === 'open' && $ticket->status?->slug !== 'open') {
            $stamps['reopened_at'] = $now;
            $stamps['resolved_at'] = null;
            $stamps['closed_at'] = null;
        }

        if ($ticket->first_response_at === null && $this->isStaffResponse($actor)) {
            $stamps['first_response_at'] = $now;
        }

        return $stamps;
    }

    /** A response by someone working the desk, not by the reporter. */
    private function isStaffResponse(?User $actor): bool
    {
        return $actor !== null
            && in_array($actor->role?->slug, ['administrator', 'technician'], true);
    }

    private function setting(string $key): ?string
    {
        $value = SystemSetting::query()->where('key', $key)->value('value');

        return $value === null ? null : trim((string) $value, "\"'");
    }
}
