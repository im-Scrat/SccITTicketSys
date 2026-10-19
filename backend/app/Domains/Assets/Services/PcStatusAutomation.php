<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\FloorPlan\Events\PcStatusChanged;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\MaintenanceStatus;
use App\Enums\PcStatus;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The one place a PC's repair status changes *without* a person picking it
 * (Phase 2.11, SDD DD-100; owner decision 2026-10-19).
 *
 * | Trigger                                   | Effect                                                        |
 * |-------------------------------------------|---------------------------------------------------------------|
 * | a ticket starts awaiting repair           | Available → Pending Repair (Needs Fix is never downgraded)    |
 * | a ticket stops awaiting repair            | Pending Repair → Available, only when nothing else is pending |
 * | repair work starts / completes / cancels  | see {@see afterRepair()} — called by MaintenanceLifecycle     |
 *
 * "Awaiting repair" is `is_open AND slug <> 'resolved'` (DD-100 D1).
 * `resolved` is seeded `is_open = true` because it means "awaiting the
 * reporter's confirmation", and auto-closes only after
 * `tickets.auto_close_days`; keying on `is_open` alone would leave a repaired
 * machine amber for up to two weeks.
 *
 * **Needs Fix is only ever set by a person.** Automation may lift a machine
 * from Available to Pending Repair, or return it from Pending Repair to
 * Available, but it never writes Needs Fix and never clears it.
 *
 * Every method runs **inside the caller's transaction**: it locks the machine
 * row before counting the other tickets and repairs that decide the outcome
 * (two tickets on one machine closing concurrently would otherwise both see
 * "the other one is still open" and neither would release it), audits inside
 * that transaction, and dispatches `PcStatusChanged`, which implements
 * `ShouldDispatchAfterCommit` — so a rolled-back caller announces nothing.
 */
final class PcStatusAutomation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function awaitsRepair(?TicketStatus $status): bool
    {
        return $status !== null && $status->is_open && $status->slug !== 'resolved';
    }

    /** A ticket was just created. */
    public function ticketOpened(Ticket $ticket, ?User $actor, ?Request $request = null): void
    {
        $ticket->loadMissing('status');

        if (! self::awaitsRepair($ticket->status)) {
            return;
        }

        $this->raise($ticket, $actor, $request, 'ticket_opened');
    }

    /** A ticket moved from one status to another. */
    public function ticketMoved(Ticket $ticket, ?TicketStatus $from, TicketStatus $to, ?User $actor, ?Request $request = null): void
    {
        $was = self::awaitsRepair($from);
        $now = self::awaitsRepair($to);

        if ($was === $now) {
            return;
        }

        if ($now) {
            $this->raise($ticket, $actor, $request, 'ticket_reopened');

            return;
        }

        $pcUnit = $this->lockedPcUnit($ticket->pc_unit_id);

        if ($pcUnit === null || $pcUnit->status !== PcStatus::PendingRepair) {
            return;
        }

        if ($this->otherTicketAwaits($pcUnit, $ticket) || $this->repairActive($pcUnit)) {
            return;
        }

        $this->apply($pcUnit, PcStatus::Available, $actor, $request, 'ticket_'.$to->slug, [
            'ticket' => $ticket->ticket_number,
        ]);
    }

    /**
     * The status a machine should hold once a repair visit has finished or been
     * withdrawn. Pure decision — `MaintenanceLifecycle` writes it, inside its
     * own transaction, alongside the record.
     *
     *  - **Completed** → Available, unless another visit is still active or a
     *    ticket still awaits repair (then Pending Repair).
     *  - **Cancelled** → whatever the visit displaced, except that a displaced
     *    Available becomes Pending Repair while repair is still awaited.
     */
    public function afterRepair(PcUnit $pcUnit, MaintenanceRecord $record, MaintenanceStatus $target): PcStatus
    {
        $stillPending = $this->repairActive($pcUnit, $record) || $this->otherTicketAwaits($pcUnit, null);

        if ($target === MaintenanceStatus::Completed) {
            return $stillPending ? PcStatus::PendingRepair : PcStatus::Available;
        }

        $restored = $record->pc_status_before ?? $pcUnit->status;

        return $restored === PcStatus::Available && $stillPending ? PcStatus::PendingRepair : $restored;
    }

    private function raise(Ticket $ticket, ?User $actor, ?Request $request, string $cause): void
    {
        $pcUnit = $this->lockedPcUnit($ticket->pc_unit_id);

        // Only Available moves. Pending Repair is already right; Needs Fix is
        // a person's judgement and outranks a report.
        if ($pcUnit === null || $pcUnit->status !== PcStatus::Available) {
            return;
        }

        $this->apply($pcUnit, PcStatus::PendingRepair, $actor, $request, $cause, [
            'ticket' => $ticket->ticket_number,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function apply(PcUnit $pcUnit, PcStatus $to, ?User $actor, ?Request $request, string $cause, array $context): void
    {
        $from = $pcUnit->status;

        $pcUnit->forceFill(['status' => $to->value])->save();

        // The `changes.status` shape is the one `UpdatePcUnit` writes, so the
        // existing asset history timeline renders it with no special case.
        $this->audit->activity(
            ActivityAction::PcUnitStatusChanged,
            actor: $actor,
            subject: $pcUnit,
            properties: [
                'changes' => ['status' => ['from' => $from->value, 'to' => $to->value]],
                'cause' => $cause,
                'automated' => true,
                ...$context,
            ],
            request: $request,
            module: 'assets',
            description: "PC unit {$pcUnit->unit_code}: {$from->label()} → {$to->label()}",
        );

        $pcUnit->loadMissing('room');

        // A machine with no room has no Floorplan viewer to tell.
        if ($pcUnit->room !== null) {
            PcStatusChanged::dispatch(
                $pcUnit->room->uuid,
                $pcUnit->uuid,
                $pcUnit->pc_name,
                $pcUnit->unit_code,
                ['value' => $to->value, 'label' => $to->label(), 'tone' => $to->tone()],
            );
        }
    }

    /** The live (not archived) machine, row-locked; null when there is none. */
    private function lockedPcUnit(?int $pcUnitId): ?PcUnit
    {
        if ($pcUnitId === null) {
            return null;
        }

        return PcUnit::query()->whereKey($pcUnitId)->lockForUpdate()->first();
    }

    private function otherTicketAwaits(PcUnit $pcUnit, ?Ticket $except): bool
    {
        return Ticket::query()
            ->where('pc_unit_id', $pcUnit->getKey())
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->whereHas('status', fn ($q) => $q->where('is_open', true)->where('slug', '<>', 'resolved'))
            ->exists();
    }

    /**
     * A repair visit that has started and not finished — in progress or paused
     * on hold. A merely scheduled visit has not touched the machine yet.
     */
    private function repairActive(PcUnit $pcUnit, ?MaintenanceRecord $except = null): bool
    {
        return MaintenanceRecord::query()
            ->where('pc_unit_id', $pcUnit->getKey())
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->whereIn('status', [MaintenanceStatus::InProgress->value, MaintenanceStatus::OnHold->value])
            ->exists();
    }
}
