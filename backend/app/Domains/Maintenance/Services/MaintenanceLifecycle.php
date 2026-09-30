<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Domains\FloorPlan\Events\PcStatusChanged;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Maintenance\Events\MaintenanceCompleted;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for a maintenance record's status
 * (SRS FR-MNT-003/004/008/010; SDD DD-55).
 *
 * Modelled on {@see TicketLifecycle} and `AssetLifecycle`, with three
 * guarantees:
 *
 *  1. **No transition is unaudited.** The record update, the PC-unit state
 *     change and the `activity_logs` row are written in **one transaction**. A
 *     status cannot move while leaving any of them behind. Unlike Tickets there
 *     is no `*_status_history` table to keep in step — DD-55 records why: no
 *     FR-MNT asks for one, and `activity_logs` already carries actor, subject
 *     and before/after values.
 *
 *  2. **Only legal transitions happen**, and an illegal one is a 422 naming the
 *     states that *are* reachable — never a silent no-op.
 *
 *  3. **Completion is gated, not merely permitted.** Required checklist items
 *     must be done (FR-MNT-004); a resolution must say what was done, for
 *     **every** type; and non-preventive work — corrective and hardware
 *     upgrades — must carry at least one repair image (FR-MNT-010, Client
 *     decisions of 2026-08-28 and 2026-08-29). The gates live here rather than
 *     in the FormRequest because they are invariants of the record, not of one
 *     HTTP shape — a future caller (WP-2.6b's scanned submission) must hit
 *     exactly the same wall.
 *
 * ── Why there is no actor column in the map ────────────────────────────────
 *
 * `TicketLifecycle` carries entitled actors per edge because "resolved → closed"
 * is legitimate for a reporter, an administrator and the scheduler, and illegal
 * for a passer-by. Maintenance has no such split in WP-2.6: every reachable
 * record already belongs to the actor by {@see MaintenanceVisibility}, so the
 * question "may you move this at all" is answered before the map is consulted,
 * and no transition here is administrator-only. Adding an actor column that
 * always reads `[admin, owner]` would be a mechanism with nothing to decide.
 *
 * `completed` and `cancelled` are both terminal. Nothing reopens a completed
 * record: a further visit is a further record (Client decision, 2026-08-28).
 */
class MaintenanceLifecycle
{
    /**
     * The transition map: current status => the statuses reachable from it.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'scheduled' => ['in_progress', 'cancelled'],
        'in_progress' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['in_progress', 'cancelled'],
        // Terminal. A completed visit is the account of what happened, and a
        // cancelled one was called off; neither is a state work resumes from.
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MaintenanceVisibility $visibility,
    ) {}

    /**
     * Move a record to a new status, recording the PC-unit effect and the audit
     * entry atomically.
     *
     * @param  array<string, mixed>  $payload  optional fields the transition itself carries
     *                                         (a resolution written as part of completing)
     *
     * @throws ValidationException when the transition is illegal or a completion
     *                             gate is unmet
     */
    public function transition(
        MaintenanceRecord $record,
        MaintenanceStatus $target,
        User $actor,
        array $payload = [],
        ?Request $request = null,
    ): MaintenanceRecord {
        $from = $record->status;

        if ($from === $target) {
            // Re-submitting the current status is a no-op, not an error — the
            // same stance AssetLifecycle takes, and it keeps a double-tapped
            // button from writing a spurious audit row.
            return $record;
        }

        $this->assertLegal($record, $target);

        if ($target === MaintenanceStatus::Completed) {
            $this->assertCompletable($record, $actor, $payload);
        }

        $pcEffect = null;

        $updated = DB::transaction(function () use ($record, $from, $target, $actor, $payload, $request, &$pcEffect): MaintenanceRecord {
            $changes = $this->stampsFor($record, $target, $payload);

            // The PC effect is computed *inside* the transaction and applied to
            // the same rows the record update touches, so a failure anywhere
            // leaves neither the record nor the machine half-moved.
            $pcChanges = $this->applyPcUnitEffect($record, $target);
            $pcEffect = $pcChanges['pc_unit'];

            $record->forceFill([...$changes, ...$pcChanges['record'], 'updated_by' => $actor->getKey()])->save();

            $this->audit->activity(
                $this->actionFor($from, $target),
                actor: $actor,
                subject: $record,
                properties: array_filter([
                    'from' => $from->value,
                    'to' => $target->value,
                    'pc_unit' => $pcChanges['pc_unit'],
                    'reason' => $payload['reason'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                request: $request,
                module: 'maintenance',
                description: "{$record->title}: {$from->label()} → {$target->label()}",
            );

            return $record->refresh();
        });

        /*
         * WP-E — the floor-plan notification seam (FR-FP-007). Outside the
         * transaction and after it has committed, same convention as
         * TicketLifecycle::transition. `$pcEffect` is non-null only when
         * `applyPcUnitEffect` actually moved the machine's status (see its own
         * docblock); a cancelled or completed record whose PC was already
         * archived, or a target that touches nothing, dispatches no event.
         */
        if ($pcEffect !== null) {
            $pcUnit = $updated->pcUnit?->fresh('room');

            if ($pcUnit instanceof PcUnit && $pcUnit->room !== null) {
                PcStatusChanged::dispatch(
                    $pcUnit->room->uuid,
                    $pcUnit->uuid,
                    $pcUnit->pc_name,
                    $pcUnit->unit_code,
                    ['value' => $pcUnit->status->value, 'label' => $pcUnit->status->label(), 'tone' => $pcUnit->status->tone()],
                );
            }
        }

        /*
         * WP-K. "After the transaction above" is NOT "after commit" when this
         * method runs nested — SubmitProofOfWork calls it inside its own
         * transaction, making the block above a savepoint. Both events here
         * therefore implement ShouldDispatchAfterCommit: the dispatcher defers
         * them to the root commit and drops them on rollback, whichever caller
         * owns the outermost transaction. (PcStatusChanged predates this
         * guarantee — WP-E assumed placement was enough; the nested path shows
         * it was not.)
         */
        if ($target === MaintenanceStatus::Completed) {
            MaintenanceCompleted::dispatch($updated);
        }

        return $updated;
    }

    /**
     * The statuses this actor could move the record to right now.
     *
     * Rendered by the client as the available action buttons, so it must be the
     * same source the transition itself consults — a button the API would
     * refuse is worse than no button.
     *
     * @return list<array{value: string, label: string, blocked_by: list<string>}>
     */
    public function availableTransitions(MaintenanceRecord $record, User $actor): array
    {
        if (! $this->visibility->canWork($actor, $record)) {
            return [];
        }

        $out = [];

        foreach ($this->reachableFrom($record->status) as $value) {
            $target = MaintenanceStatus::from($value);

            if ($target === MaintenanceStatus::Completed) {
                if (! $actor->can('complete', $record)) {
                    continue;
                }

                // Offered even when blocked, with the reasons attached: a
                // technician needs to know *why* they cannot finish, and a
                // silently missing button teaches them nothing.
                $out[] = [
                    'value' => $value,
                    'label' => $target->label(),
                    'blocked_by' => $this->completionBlockers($record),
                ];

                continue;
            }

            $out[] = ['value' => $value, 'label' => $target->label(), 'blocked_by' => []];
        }

        return $out;
    }

    /**
     * The statuses reachable from a given one, for error messages and tests.
     *
     * The map is total over the enum — every case has an entry, terminal ones
     * an empty list — so there is no missing-key case to defend against.
     *
     * @return list<string>
     */
    public function reachableFrom(MaintenanceStatus $status): array
    {
        return self::TRANSITIONS[$status->value];
    }

    /* --------------------------------------------------------------- gates */

    /**
     * @throws ValidationException
     */
    private function assertLegal(MaintenanceRecord $record, MaintenanceStatus $target): void
    {
        $reachable = $this->reachableFrom($record->status);

        if (in_array($target->value, $reachable, true)) {
            return;
        }

        $message = $reachable === []
            ? "This record is {$record->status->label()} and cannot change status."
            : 'That status change is not allowed from '.$record->status->label().'.';

        throw ValidationException::withMessages([
            'status' => $message,
        ])->status(422);
    }

    /**
     * Completion gates (SRS FR-MNT-004/010).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function assertCompletable(MaintenanceRecord $record, User $actor, array $payload): void
    {
        if (! $actor->can('complete', $record)) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to complete maintenance.',
            ])->status(422);
        }

        $blockers = $this->completionBlockers($record, $payload);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['status' => $blockers])->status(422);
        }
    }

    /**
     * Why this record cannot be completed yet — empty when it can.
     *
     * One method, consulted by both {@see assertCompletable()} and
     * {@see availableTransitions()}, so the reason shown to the technician is
     * always the reason the server would actually give.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function completionBlockers(MaintenanceRecord $record, array $payload = []): array
    {
        $blockers = [];

        // FR-MNT-004: required-item enforcement before completion. Read from
        // the instantiated rows, whose `is_required` was copied off the template
        // at issue time and therefore survives the template being edited.
        $outstanding = $record->checklists()
            ->where('is_required', true)
            ->where('is_completed', false)
            ->count();

        if ($outstanding > 0) {
            $blockers[] = $outstanding === 1
                ? 'One required checklist item is still outstanding.'
                : "{$outstanding} required checklist items are still outstanding.";
        }

        // A completed visit with no account of what was done is the thing
        // FR-MNT-003's `resolution` field exists to prevent.
        //
        // **Required for every maintenance type** (Client decision, 2026-08-29),
        // deliberately unlike the evidence rule below. A photograph shows a
        // machine; only the resolution says what was done to it, and a cleaning
        // round that records nothing is indistinguishable from one that never
        // happened.
        $resolution = trim((string) ($payload['resolution'] ?? $record->resolution ?? ''));

        if ($resolution === '') {
            $blockers[] = 'Record what was done before completing this maintenance.';
        }

        /*
         * FR-MNT-010, on the Client's business rule of 2026-08-29:
         *
         *   corrective         evidence REQUIRED
         *   hardware upgrade   evidence REQUIRED
         *   preventive         supported, not universally required
         *   inspection         optional
         *   cleaning           optional
         *
         * That rule is read straight off `maintenance_types.is_preventive`,
         * which already partitions the seeded catalogue exactly that way —
         * corrective and hardware-upgrade are the two non-preventive types, and
         * preventive, inspection and cleaning are the three preventive ones. So
         * no per-type evidence column, no configuration table and no schema
         * change: the distinction the Client drew is the distinction the data
         * already carries, and inventing a second place to record it would only
         * create somewhere for the two to disagree.
         *
         * A type added later inherits the rule from its own `is_preventive`
         * flag, which is the honest default — an administrator adding
         * "Emergency repair" gets the corrective treatment without anyone
         * having to remember a second setting.
         */
        if ($record->type !== null && ! $record->type->is_preventive && $record->images()->count() === 0) {
            $blockers[] = 'Attach at least one repair image before completing this maintenance.';
        }

        return $blockers;
    }

    /* -------------------------------------------------------------- effects */

    /**
     * Timestamps and text the transition itself writes.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stampsFor(MaintenanceRecord $record, MaintenanceStatus $target, array $payload): array
    {
        $changes = ['status' => $target->value];

        if ($target === MaintenanceStatus::InProgress && $record->started_at === null) {
            // Stamped once. Resuming from on-hold is still the same visit, so
            // it must not reset when work actually began.
            $changes['started_at'] = now();
        }

        if ($target === MaintenanceStatus::Completed) {
            $changes['completed_at'] = now();
            $changes['maintenance_date'] = $record->maintenance_date ?? now();

            if (isset($payload['resolution']) && trim((string) $payload['resolution']) !== '') {
                $changes['resolution'] = (string) $payload['resolution'];
            }
        }

        return $changes;
    }

    /**
     * The PC-unit side of a transition (SRS FR-MNT-008).
     *
     * Starting work puts the machine into `under_maintenance` and remembers what
     * it displaced. Completing restores that status and marks the machine
     * `working` — the requirement's own example, and the honest reading of
     * "this visit is finished". Cancelling restores **both** prior values
     * exactly, because a cancelled visit asserts nothing about the machine.
     *
     * If a second record starts work on a machine already under maintenance,
     * `under_maintenance` is what gets remembered — so completing the first
     * visit correctly leaves the machine under maintenance for the second. That
     * falls out of storing the displaced value rather than assuming one.
     *
     * @return array{record: array<string, mixed>, pc_unit: array<string, mixed>|null}
     */
    private function applyPcUnitEffect(MaintenanceRecord $record, MaintenanceStatus $target): array
    {
        $pcUnit = $record->pcUnit;

        if (! $pcUnit instanceof PcUnit) {
            // Asset-only records, and records whose PC was archived, touch
            // nothing — and record nothing, so the audit entry does not imply a
            // machine change that never happened.
            return ['record' => [], 'pc_unit' => null];
        }

        if ($target === MaintenanceStatus::InProgress && $record->pc_status_before === null) {
            $before = ['status' => $pcUnit->status, 'condition' => $pcUnit->current_condition];

            $pcUnit->forceFill(['status' => PcStatus::UnderMaintenance->value])->save();

            return [
                'record' => [
                    'pc_status_before' => $before['status']->value,
                    'pc_condition_before' => $before['condition']->value,
                ],
                'pc_unit' => [
                    'id' => $pcUnit->uuid,
                    'status_from' => $before['status']->value,
                    'status_to' => PcStatus::UnderMaintenance->value,
                ],
            ];
        }

        if ($target === MaintenanceStatus::Completed && $record->pc_status_before !== null) {
            $restored = $record->pc_status_before->value;

            $pcUnit->forceFill([
                'status' => $restored,
                'current_condition' => PcCondition::Working->value,
            ])->save();

            return [
                'record' => [],
                'pc_unit' => [
                    'id' => $pcUnit->uuid,
                    'status_from' => PcStatus::UnderMaintenance->value,
                    'status_to' => $restored,
                    'condition_to' => PcCondition::Working->value,
                ],
            ];
        }

        if ($target === MaintenanceStatus::Cancelled && $record->pc_status_before !== null) {
            $pcUnit->forceFill([
                'status' => $record->pc_status_before->value,
                'current_condition' => ($record->pc_condition_before ?? $pcUnit->current_condition)->value,
            ])->save();

            return [
                'record' => [],
                'pc_unit' => [
                    'id' => $pcUnit->uuid,
                    'status_to' => $record->pc_status_before->value,
                    'restored' => true,
                ],
            ];
        }

        return ['record' => [], 'pc_unit' => null];
    }

    /**
     * Resuming held work is a different event from starting it, and the
     * timeline should say so — "Work started" appearing twice on one record
     * would misdescribe a visit that was simply paused.
     */
    private function actionFor(MaintenanceStatus $from, MaintenanceStatus $target): ActivityAction
    {
        return match ($target) {
            MaintenanceStatus::InProgress => $from === MaintenanceStatus::OnHold
                ? ActivityAction::MaintenanceResumed
                : ActivityAction::MaintenanceStarted,
            MaintenanceStatus::OnHold => ActivityAction::MaintenanceHeld,
            MaintenanceStatus::Completed => ActivityAction::MaintenanceCompleted,
            MaintenanceStatus::Cancelled => ActivityAction::MaintenanceCancelled,
            MaintenanceStatus::Scheduled => ActivityAction::MaintenanceUpdated,
        };
    }
}
