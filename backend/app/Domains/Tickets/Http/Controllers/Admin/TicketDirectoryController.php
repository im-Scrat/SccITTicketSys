<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Http\Controllers\Admin;

use App\Domains\Analytics\Services\TicketMetrics;
use App\Domains\Tickets\Actions\UpdateTicket;
use App\Domains\Tickets\Http\Requests\IndexTicketsRequest;
use App\Domains\Tickets\Http\Resources\TicketDetailResource;
use App\Domains\Tickets\Http\Resources\TicketListResource;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Domains\Tickets\Services\TicketDirectoryQuery;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The Administrator's ticket oversight surface (SRS FR-TKT-013/014, FR-DSH-003).
 *
 * Administrator-only, and enforced by `TicketPolicy::viewAdministrative()` on
 * every method rather than by the route gate alone — `tickets.view` is held by
 * all three roles, so the route gate cannot be the thing that closes this.
 *
 * The triage overview is the working surface: unassigned open tickets first,
 * because an unowned fault is the one thing on this screen that nobody is
 * currently doing anything about.
 */
class TicketDirectoryController extends Controller
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly TicketLifecycle $lifecycle,
    ) {}

    /** The full directory: search, filter, sort, paginate. */
    public function index(IndexTicketsRequest $request, TicketDirectoryQuery $directory): AnonymousResourceCollection
    {
        $this->authorize('viewAdministrative', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        $tickets = $directory->directory($request->validated(), $user);
        $this->attachSlaPosture($tickets->getCollection());

        return TicketListResource::collection($tickets);
    }

    /**
     * The triage overview — the figures an administrator acts on, reusing
     * `TicketMetrics` rather than recomputing them, so this page and the role
     * dashboard can never disagree.
     */
    public function dashboard(Request $request, TicketMetrics $metrics, TicketDirectoryQuery $directory): JsonResponse
    {
        $this->authorize('viewAdministrative', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        $sla = $metrics->slaPosture();

        $unassigned = $directory->directory(
            ['technician' => 'unassigned', 'sort' => 'priority', 'direction' => 'desc', 'per_page' => 10],
            $user,
        );
        $this->attachSlaPosture($unassigned->getCollection());

        $awaiting = $directory->directory(
            ['awaiting_confirmation' => true, 'sort' => 'updated_at', 'per_page' => 10],
            $user,
        );
        $this->attachSlaPosture($awaiting->getCollection());

        return response()->json([
            'data' => [
                'summary' => [
                    'open_backlog' => $metrics->openBacklog(),
                    'unassigned' => $metrics->unassignedOpen(),
                    'breached' => $sla['breached'],
                    'at_risk' => $sla['at_risk'],
                    'lead_hours' => $sla['lead_hours'],
                    'awaiting_confirmation' => $awaiting->total(),
                ],
                'by_status' => $metrics->byStatus(),
                'by_priority' => $metrics->openByPriority(),
                'technician_workload' => $metrics->technicianWorkload(8),
                'triage_queue' => TicketListResource::collection($unassigned->getCollection())->resolve(),
                'awaiting_confirmation' => TicketListResource::collection($awaiting->getCollection())->resolve(),
                'auto_close_days' => $this->lifecycle->autoCloseDays(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** The full record, including internal detail and the AI snapshot. */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('viewAdministrative', Ticket::class);

        /** @var User $user */
        $user = $request->user();

        $ticket->load([
            'status', 'priority', 'category', 'tags',
            'reporter', 'assignedTechnician', 'duplicateOf',
            'pcUnit.room.floor.building', 'room.floor.building',
            'attachments.uploadedBy',
        ]);

        return (new TicketDetailResource($ticket))
            ->additional(['meta' => [
                'transitions' => $this->lifecycle->availableTransitions($ticket, $user),
                'sla' => $this->sla->posture($ticket),
                'reopen_window_days' => $this->lifecycle->reopenWindowDays(),
            ]])
            ->response();
    }

    /** Set or override the priority, recomputing the SLA (FR-TKT-004). */
    public function changePriority(Request $request, Ticket $ticket, UpdateTicket $action): JsonResponse
    {
        $this->authorize('changePriority', $ticket);

        $validated = $request->validate([
            'priority' => ['required', 'string', 'exists:ticket_priorities,slug'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $priority = TicketPriority::query()->where('slug', $validated['priority'])->firstOrFail();
        $action->changePriority($ticket, $priority, $actor, $request, $validated['reason'] ?? null);

        return (new TicketDetailResource($ticket->load(['status', 'priority', 'category'])))
            ->additional(['message' => "Priority set to {$priority->name}."])
            ->response();
    }

    /** Link this ticket to the canonical one it duplicates (FR-TKT-011). */
    public function markDuplicate(Request $request, Ticket $ticket, UpdateTicket $action): JsonResponse
    {
        $this->authorize('markDuplicate', $ticket);

        $validated = $request->validate([
            'duplicate_of' => ['present', 'nullable', 'string', 'uuid', 'exists:tickets,uuid'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $canonical = is_string($validated['duplicate_of']) && $validated['duplicate_of'] !== ''
            ? Ticket::query()->where('uuid', $validated['duplicate_of'])->firstOrFail()
            : null;

        $action->markDuplicate($ticket, $canonical, $actor, $request);

        return (new TicketDetailResource($ticket->load(['status', 'priority', 'category', 'duplicateOf'])))
            ->additional([
                'message' => $canonical !== null
                    ? "Marked as a duplicate of {$canonical->ticket_number}."
                    : 'Duplicate link removed.',
            ])
            ->response();
    }

    /**
     * Compute SLA posture once per page.
     *
     * Doing it here rather than inside the resource means a 20-row page makes
     * one pass against a single `now()`, instead of twenty comparisons at
     * slightly different instants.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    private function attachSlaPosture(Collection $tickets): void
    {
        $tickets->each(function (Ticket $ticket): void {
            $ticket->sla_posture = $this->sla->posture($ticket);
        });
    }
}
