<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Tickets\Services\TicketLifecycle;
use App\Enums\ActivityAction;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Move a ticket through its lifecycle (SRS FR-TKT-005).
 *
 * A thin seam over {@see TicketLifecycle}, kept for symmetry with the other
 * Actions so every controller in this domain talks to the same layer — the same
 * shape `ChangeAssetStatus` takes over `AssetLifecycle`.
 *
 * Its one piece of real work is choosing the **audit verb**. "Status changed" is
 * true of every transition but tells a reader nothing; a timeline that says
 * *Resolution confirmed*, *Reopened* or *Cancelled* explains what actually
 * happened. The transition rules themselves stay in the lifecycle service.
 */
class ChangeTicketStatus
{
    public function __construct(private readonly TicketLifecycle $lifecycle) {}

    public function handle(
        Ticket $ticket,
        TicketStatus $target,
        ?User $actor,
        Request $request,
        ?string $remarks = null,
    ): Ticket {
        return $this->lifecycle->transition(
            $ticket,
            $target,
            $actor,
            $remarks,
            $request,
            $this->actionFor($ticket, $target, $actor),
        );
    }

    /**
     * The audit verb that describes this particular move.
     *
     * Derived from where the ticket is going *and who is moving it*: a reporter
     * closing a resolved ticket is confirming a fix, while an administrator
     * doing the same is overriding a silent requester. Those are different
     * events and the timeline should say so.
     */
    private function actionFor(Ticket $ticket, TicketStatus $target, ?User $actor): ActivityAction
    {
        $isReporter = $actor !== null && $ticket->reporter_id === $actor->getKey();

        return match (true) {
            $actor === null && $target->slug === 'closed' => ActivityAction::TicketAutoClosed,
            $target->slug === 'closed' && $isReporter => ActivityAction::TicketResolutionConfirmed,
            $target->slug === 'cancelled' => ActivityAction::TicketCancelled,
            $target->slug === 'open' && $ticket->status?->is_terminal => ActivityAction::TicketReopened,
            $target->slug === 'open' && $ticket->status?->slug === 'resolved' => ActivityAction::TicketReopened,
            $target->slug === 'in-progress' => ActivityAction::TicketWorkStarted,
            $target->slug === 'on-hold' => ActivityAction::TicketWorkHeld,
            $target->slug === 'resolved' => ActivityAction::TicketWorkCompleted,
            default => ActivityAction::TicketStatusChanged,
        };
    }
}
