<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\Events\MaintenanceRescheduled;
use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Domains\WorkSupport\Events\WorkSupportRequestDecided;
use App\Enums\ActivityAction;
use App\Enums\WorkSupportStatus;
use App\Models\MaintenanceRecord;
use App\Models\TicketStatus;
use App\Models\User;
use App\Models\WorkSupportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **The single write path for a support request's status**
 * (SRS FR-WSR-004/006/007/008/011/014; SDD DD-54).
 *
 * Modelled on {@see TicketLifecycle} and the Maintenance module's own lifecycle, and like
 * `TicketLifecycle` the map carries **who** as well as **what** — because
 * `submitted → cancelled` is legitimate for the submitting technician and for
 * an administrator, while `submitted → approved` is an administrator's alone.
 * Maintenance did not need that column; this does.
 *
 * ── There is no `PATCH /status`, and that is the whole point ───────────────
 *
 * FR-WSR-004: *"Transitions shall be enforced by a transition map, not by
 * accepting a status field from the client — an arbitrary status update shall
 * be refused."* Every move below is a distinct, separately-authorized operation
 * with its own required fields. DD-54 gives the reason: when a status is
 * settable, the one transition nobody validates is the one that eventually
 * corrupts the record.
 *
 * ── Nothing is overwritten in place ────────────────────────────────────────
 *
 * FR-WSR-004 also requires that *"no prior decision, schedule or workflow state
 * shall be overwritten in place: a superseded value shall remain recoverable
 * from the request's history."* Two mechanisms, together:
 *
 *  1. The map itself. `approved` reaches only `closed`, so a second `approve`
 *     is refused rather than silently re-deciding — there is no edge on which a
 *     decision could be replaced.
 *  2. Every transition writes an `activity_logs` row carrying the **before and
 *     after** of whatever moved, inside the same transaction. That is the
 *     history; there is deliberately no `work_support_request_events` table,
 *     for the reason DD-55 gave when it declined one for maintenance — a second
 *     place for the truth to live is a second place for it to be wrong.
 *
 * ── The row lock is not decoration ─────────────────────────────────────────
 *
 * Two administrators opening the same request and pressing Approve and Decline
 * within a second of each other is an ordinary Monday. Each transition re-reads
 * its row `FOR UPDATE` and re-asserts the current state *inside* the
 * transaction, so the second one is refused by the map rather than overwriting
 * the first one's decision.
 */
class WorkSupportRequestLifecycle
{
    /** Who may perform a transition. */
    public const ACTOR_ADMIN = 'administrator';

    /** The technician who submitted the request. */
    public const ACTOR_SUBMITTER = 'submitter';

    /**
     * The transition map: `from => [to => list of entitled actors]`.
     *
     * Exactly the six states of FR-WSR-004 and no others — `under_review` was
     * considered and declined (SRS OI-11), because it would need a
     * claim/release mechanic to stay truthful.
     *
     * `clarification_requested` is **not a decision**: it returns to `approved`
     * or `declined` after the discussion, which is why it keeps every edge
     * `submitted` has.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const TRANSITIONS = [
        'submitted' => [
            'clarification_requested' => [self::ACTOR_ADMIN],
            'approved' => [self::ACTOR_ADMIN],
            'declined' => [self::ACTOR_ADMIN],
            'cancelled' => [self::ACTOR_ADMIN, self::ACTOR_SUBMITTER],
        ],
        'clarification_requested' => [
            'approved' => [self::ACTOR_ADMIN],
            'declined' => [self::ACTOR_ADMIN],
            'cancelled' => [self::ACTOR_ADMIN, self::ACTOR_SUBMITTER],
        ],
        // A decision has been recorded. It is never replaced — only concluded.
        'approved' => ['closed' => [self::ACTOR_ADMIN]],
        'declined' => ['closed' => [self::ACTOR_ADMIN]],
        // Terminal. A withdrawal is not reopened and a conclusion is not undone;
        // a further need is a further request (FR-WSR-014).
        'cancelled' => [],
        'closed' => [],
    ];

    /**
     * The three transitions that are an **answer** to the technician, and
     * therefore the three that notify them (FR-WSR-012).
     *
     * `cancelled` and `closed` are deliberately absent — see the note in
     * {@see transition()}.
     *
     * @var list<WorkSupportStatus>
     */
    private const DECISIONS = [
        WorkSupportStatus::Approved,
        WorkSupportStatus::ClarificationRequested,
        WorkSupportStatus::Declined,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkSupportVisibility $visibility,
    ) {}

    /* ------------------------------------------------- administrator moves */

    /**
     * **Decision A — approve and reschedule** (FR-WSR-006).
     *
     * The reschedule propagates to the linked maintenance record, and the
     * previous date goes into the audit properties rather than being silently
     * replaced. The linked ticket moves to a held state where the map allows it
     * — see {@see holdTicket()} for why "where the map allows it" is doing real
     * work there.
     *
     * @param  array{rescheduled_to: string, reschedule_reason?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function approve(WorkSupportRequest $request, User $actor, array $data, ?Request $http = null): WorkSupportRequest
    {
        return $this->transition(
            $request,
            WorkSupportStatus::Approved,
            $actor,
            ActivityAction::WorkSupportRequestApproved,
            function (WorkSupportRequest $locked) use ($data, $actor, $http): array {
                $changes = [
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    'rescheduled_to' => $data['rescheduled_to'],
                    'reschedule_reason' => $data['reschedule_reason'] ?? null,
                ];

                $properties = [
                    'rescheduled_to' => $data['rescheduled_to'],
                    'reschedule_reason' => $data['reschedule_reason'] ?? null,
                ];

                return [
                    'changes' => $changes,
                    'properties' => $properties
                        + $this->rescheduleMaintenance($locked, $data['rescheduled_to'], $actor, $http)
                        + $this->holdTicket($locked, $actor, $http),
                ];
            },
            $http,
        );
    }

    /**
     * **Decision B — request a face-to-face discussion** (FR-WSR-007).
     *
     * Records that a discussion is wanted, and nothing more. SRS OI-10 settled
     * that this is **not** a calendar: no availability, no invitations, no
     * reminders. `proposed_meeting_at` is a note about a time, not a booking.
     *
     * @param  array{clarification_reason: string, proposed_meeting_at?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function requestClarification(WorkSupportRequest $request, User $actor, array $data, ?Request $http = null): WorkSupportRequest
    {
        return $this->transition(
            $request,
            WorkSupportStatus::ClarificationRequested,
            $actor,
            ActivityAction::WorkSupportClarificationRequested,
            fn (): array => [
                /*
                 * `decided_by` and `decided_at` are deliberately NOT written.
                 * Asking to talk is not a decision, and stamping the decision
                 * columns here would make the request look settled to every
                 * query that reads them — including the administrator inbox's
                 * own "needs attention" filter.
                 */
                'changes' => [
                    'clarification_reason' => $data['clarification_reason'],
                    'proposed_meeting_at' => $data['proposed_meeting_at'] ?? null,
                ],
                'properties' => [
                    'reason' => $data['clarification_reason'],
                    'proposed_meeting_at' => $data['proposed_meeting_at'] ?? null,
                ],
            ],
            $http,
        );
    }

    /**
     * **Decision C — decline** (FR-WSR-008).
     *
     * A reason is refused in three independent places, and this is the second:
     * the FormRequest rejects an empty one, this asserts it again for callers
     * that are not HTTP, and the database's own CHECK refuses the row outright
     * (DR-020). The requirement asks for server-side enforcement; three layers
     * is what makes that true of a command or a job as well as of a controller.
     *
     * @param  array{decline_reason: string}  $data
     *
     * @throws ValidationException
     */
    public function decline(WorkSupportRequest $request, User $actor, array $data, ?Request $http = null): WorkSupportRequest
    {
        $reason = trim($data['decline_reason']);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'decline_reason' => 'Explain why the request is declined. A decline without a reason is not recorded.',
            ])->status(422);
        }

        return $this->transition(
            $request,
            WorkSupportStatus::Declined,
            $actor,
            ActivityAction::WorkSupportRequestDeclined,
            fn (): array => [
                'changes' => [
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    'decline_reason' => $reason,
                ],
                'properties' => ['reason' => $reason],
            ],
            $http,
        );
    }

    /**
     * Conclude a decided request.
     *
     * Administrator-only and explicit. The plan's OD-9 also proposed an
     * automatic close when the linked maintenance record completes; that half is
     * **not implemented here** because it would mean reaching into the
     * maintenance lifecycle, which Stage D delivered and the Client approved as
     * it stands. It is recorded as an open decision rather than assumed.
     *
     * @throws ValidationException
     */
    public function close(WorkSupportRequest $request, User $actor, ?Request $http = null): WorkSupportRequest
    {
        return $this->transition(
            $request,
            WorkSupportStatus::Closed,
            $actor,
            ActivityAction::WorkSupportRequestClosed,
            fn (): array => ['changes' => ['closed_at' => now()], 'properties' => []],
            $http,
        );
    }

    /* ----------------------------------------------------- technician moves */

    /**
     * Withdraw a request (FR-WSR-014).
     *
     * Available to the submitting technician **or** an administrator on their
     * behalf; `cancelled_by` records which, because "who withdrew this" is
     * precisely the question an audit of a withdrawn request asks.
     *
     * @param  array{cancellation_note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function cancel(WorkSupportRequest $request, User $actor, array $data = [], ?Request $http = null): WorkSupportRequest
    {
        return $this->transition(
            $request,
            WorkSupportStatus::Cancelled,
            $actor,
            ActivityAction::WorkSupportRequestCancelled,
            fn (): array => [
                'changes' => [
                    'cancelled_by' => $actor->getKey(),
                    'cancelled_at' => now(),
                    'cancellation_note' => $data['cancellation_note'] ?? null,
                ],
                'properties' => array_filter([
                    'note' => $data['cancellation_note'] ?? null,
                    'by' => $this->actorRole($request, $actor),
                ]),
            ],
            $http,
        );
    }

    /**
     * Record the technician's acknowledgement of a new schedule (FR-WSR-006).
     *
     * **Not a transition.** An acknowledgement does not change what the request
     * *is* — it stays `approved` — so it sets a timestamp and writes its own
     * audit row without touching the map. Modelling it as a status would have
     * added a seventh state the Client never named.
     *
     * @throws ValidationException
     */
    public function acknowledge(WorkSupportRequest $request, User $actor, ?Request $http = null): WorkSupportRequest
    {
        return DB::transaction(function () use ($request, $actor, $http): WorkSupportRequest {
            /** @var WorkSupportRequest $locked */
            $locked = WorkSupportRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkSupportStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => 'Only an approved request carries a schedule to acknowledge.',
                ])->status(422);
            }

            if ($locked->acknowledged_at !== null) {
                // Idempotent: acknowledging twice is one acknowledgement, and a
                // second audit row would misdescribe the timeline.
                return $locked;
            }

            $locked->forceFill(['acknowledged_at' => now()])->save();

            $this->audit->activity(
                ActivityAction::WorkSupportRequestAcknowledged,
                actor: $actor,
                subject: $locked,
                properties: ['rescheduled_to' => $locked->rescheduled_to?->toIso8601String()],
                request: $http,
                module: 'work-support',
                description: 'New schedule acknowledged',
            );

            return $locked->refresh();
        });
    }

    /* ------------------------------------------------------------ the map */

    /**
     * The statuses reachable from a given one, for error messages and tests.
     *
     * Total over the enum — every case has an entry, terminal ones an empty
     * list — so there is no missing-key case to defend against.
     *
     * @return list<string>
     */
    public function reachableFrom(WorkSupportStatus $status): array
    {
        return array_keys(self::TRANSITIONS[$status->value]);
    }

    /**
     * What this actor could do to this request right now.
     *
     * Rendered by both clients as the available buttons, so it must be the same
     * source the transition itself consults — an offered action the API would
     * refuse is worse than no button at all.
     *
     * @return list<array{value: string, label: string}>
     */
    public function availableTransitions(WorkSupportRequest $request, User $actor): array
    {
        $role = $this->actorRole($request, $actor);

        if ($role === null) {
            return [];
        }

        $out = [];

        foreach (self::TRANSITIONS[$request->status->value] as $target => $actors) {
            if (in_array($role, $actors, true)) {
                $status = WorkSupportStatus::from($target);
                $out[] = ['value' => $status->value, 'label' => $status->label()];
            }
        }

        return $out;
    }

    /* --------------------------------------------------------- internals */

    /**
     * Run one transition: lock, assert, apply, audit — all in one transaction.
     *
     * @param  callable(WorkSupportRequest): array{changes: array<string, mixed>, properties: array<string, mixed>}  $effect
     *
     * @throws ValidationException
     */
    private function transition(
        WorkSupportRequest $request,
        WorkSupportStatus $target,
        User $actor,
        ActivityAction $action,
        callable $effect,
        ?Request $http,
    ): WorkSupportRequest {
        return DB::transaction(function () use ($request, $target, $actor, $action, $effect, $http): WorkSupportRequest {
            /*
             * Re-read under a row lock and re-assert *inside* the transaction.
             * The state this method was called about may already be stale — a
             * second administrator may have decided it while this request was
             * in flight — and the assertion below is what turns that race into
             * a clean 422 instead of a silently replaced decision.
             */
            /** @var WorkSupportRequest $locked */
            $locked = WorkSupportRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            $this->assertLegal($locked, $target, $actor);

            $result = $effect($locked);

            $locked->forceFill([...$result['changes'], 'status' => $target->value])->save();

            $this->audit->activity(
                $action,
                actor: $actor,
                subject: $locked,
                properties: array_filter([
                    'from' => $from->value,
                    'to' => $target->value,
                    ...$result['properties'],
                ], static fn (mixed $value): bool => $value !== null),
                request: $http,
                module: 'work-support',
                description: "Support request {$from->label()} → {$target->label()}",
            );

            /*
             * WP-2.7a — the notification seam (FR-WSR-012, FR-NOT-003 T10).
             *
             * Raised here, in the one method every transition passes through,
             * rather than three times in the three decision methods. The map is
             * already the single place that decides a move is legal; making it
             * also the single place a decision is announced means a future
             * fourth decision cannot be added without one.
             *
             * Only the three *decisions* qualify. `cancelled` is the
             * technician's own withdrawal and `closed` is housekeeping on an
             * answer they were already given, so neither is news to them.
             *
             * Inside the transaction is safe: the dispatcher defers delivery to
             * `DB::afterCommit()`, so a decision that loses the row-lock race
             * and rolls back announces nothing.
             */
            if (in_array($target, self::DECISIONS, true)) {
                WorkSupportRequestDecided::dispatch($locked, $target, $actor);
            }

            return $locked->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertLegal(WorkSupportRequest $request, WorkSupportStatus $target, User $actor): void
    {
        $edges = self::TRANSITIONS[$request->status->value];

        if (! array_key_exists($target->value, $edges)) {
            $reachable = array_keys($edges);

            throw ValidationException::withMessages([
                'status' => $reachable === []
                    ? "This request is {$request->status->label()} and cannot change."
                    : "That change is not allowed from {$request->status->label()}.",
            ])->status(422);
        }

        $role = $this->actorRole($request, $actor);

        if ($role === null || ! in_array($role, $edges[$target->value], true)) {
            /*
             * Entitlement is part of the map, not a separate check bolted on
             * after it. A technician cancelling their own request and an
             * administrator declining it are different edges, and conflating
             * "the move is legal" with "you may make it" is how a technician
             * ends up able to approve their own request for a part.
             */
            throw ValidationException::withMessages([
                'status' => 'You are not able to make that change to this request.',
            ])->status(422);
        }
    }

    /**
     * Which actor this user is **for this request** — the map's vocabulary.
     *
     * Returns null for anyone who is neither, which `assertLegal()` treats as a
     * refusal rather than as a missing case.
     *
     * @return self::ACTOR_*|null
     */
    private function actorRole(WorkSupportRequest $request, User $actor): ?string
    {
        if ($this->visibility->canDecide($actor)) {
            return self::ACTOR_ADMIN;
        }

        /*
         * `canSee()` rather than a `technician_id` comparison. For a non-admin
         * it *is* "the floor, and this request is yours" — the identical rule
         * the list query and the policy use — so asking the visibility service
         * keeps one definition of ownership rather than two that could drift.
         * Administrators never reach this line; the branch above claims them.
         */
        if ($this->visibility->canSee($actor, $request)) {
            return self::ACTOR_SUBMITTER;
        }

        return null;
    }

    /**
     * Push the approved date onto the linked maintenance record (FR-WSR-006).
     *
     * The previous value is returned so it lands in the audit properties. That
     * is the whole of FR-WSR-004's "not overwritten in place": the column moves,
     * and what it used to say stays recoverable from the history.
     *
     * @return array<string, mixed>
     */
    private function rescheduleMaintenance(WorkSupportRequest $request, string $rescheduledTo, User $actor, ?Request $http): array
    {
        $record = $request->maintenanceRecord;

        /*
         * `OPEN_STATUSES` is `MaintenanceVisibility`'s own expression of "still
         * being worked", called rather than restated — rescheduling a completed
         * visit would move a date on a record that is the account of work
         * already done.
         */
        if (! $record instanceof MaintenanceRecord
            || ! in_array($record->status, MaintenanceVisibility::OPEN_STATUSES, true)
        ) {
            return [];
        }

        $previous = $record->scheduled_for?->toIso8601String();

        $record->forceFill([
            'scheduled_for' => $rescheduledTo,
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->audit->activity(
            ActivityAction::MaintenanceRescheduled,
            actor: $actor,
            subject: $record,
            properties: [
                'from' => $previous,
                'to' => $rescheduledTo,
                'reason' => 'Work support request approved',
                'work_support_request' => $request->uuid,
            ],
            request: $http,
            module: 'maintenance',
            description: "Maintenance rescheduled: {$record->title}",
        );

        /*
         * WP-2.7a — the same T6 event the administrator's direct edit raises
         * (FR-WSR-006, FR-NOT-003 T6).
         *
         * This is the path that makes the trigger worth having: a technician who
         * asked for a part and walked away is the person least likely to be
         * watching the maintenance record when its date moves underneath them.
         */
        MaintenanceRescheduled::dispatch(
            $record,
            $previous,
            $rescheduledTo,
            $actor,
            'Work support request approved',
        );

        return ['maintenance_record' => $record->uuid, 'previous_scheduled_for' => $previous];
    }

    /**
     * Move the linked ticket to a held state (FR-WSR-006, Client decision OD-2).
     *
     * FR-WSR-006 requires the ticket to move to "a held/awaiting state rather
     * than being resolved or closed", but names no slug, and the WP-2.6
     * transition map has **no `open → on-hold` edge**. The approved resolution
     * is to use `on-hold` where the map permits it and, where it does not,
     * **leave the ticket alone and record why** — rather than adding an edge to
     * a shipped, verified map to satisfy a requirement that never asked for one.
     *
     * @return array<string, mixed>
     */
    private function holdTicket(WorkSupportRequest $request, User $actor, ?Request $http): array
    {
        $ticket = $request->ticket;

        if ($ticket === null) {
            return [];
        }

        $slug = $ticket->status?->slug;

        if ($slug === 'on-hold') {
            return ['ticket' => $ticket->ticket_number, 'ticket_hold' => 'already_held'];
        }

        if (! in_array($slug, ['assigned', 'in-progress'], true)) {
            // The honest outcome: say what was not done and why, so nobody
            // reading the timeline concludes the hold silently failed.
            return [
                'ticket' => $ticket->ticket_number,
                'ticket_hold' => 'skipped_no_legal_transition',
                'ticket_status' => $slug,
            ];
        }

        $onHold = TicketStatus::query()->where('slug', 'on-hold')->first();

        if ($onHold === null) {
            return ['ticket' => $ticket->ticket_number, 'ticket_hold' => 'skipped_status_missing'];
        }

        app(TicketLifecycle::class)->transition(
            $ticket,
            $onHold,
            $actor,
            'Awaiting parts or a decision on an approved work support request',
            $http,
        );

        return ['ticket' => $ticket->ticket_number, 'ticket_hold' => 'on-hold'];
    }
}
