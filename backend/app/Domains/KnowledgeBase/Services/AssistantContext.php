<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use App\Models\User;

/**
 * **The allowlist** of what the assistant may know about the person asking and
 * the records it is shown (pre-commit fix pass, Phase 2.9; SRS FR-AI-030).
 *
 * ── Why an allowlist, and why here ─────────────────────────────────────────
 *
 * The provider is a third party. Whatever is sent may be logged or retained
 * under the API's own terms, so the only data that is safe there is data that
 * was never sent. A redactor that strips *known-bad* fields fails open the day
 * someone adds a column; this class instead names, field by field, the only
 * things that may enter a prompt, and every assistant prompt is assembled from
 * it. Nothing here receives a model to serialise — no `toArray()`, no resource,
 * no request, no session.
 *
 *   about the user   their role, and what their role lets them do — as plain
 *                    phrases from a fixed permission map. Never a name, email,
 *                    employee number, phone, id/uuid, status, IP, timestamp,
 *                    password, hash or token.
 *   about a ticket   reference, title, status, category, date; for a ticket the
 *                    caller attached *and* `viewFull` admitted, its description.
 *                    The stored AI summary only for an administrator — the same
 *                    rule `TicketDetailResource` applies to `ai.summary`, so the
 *                    assistant cannot become a side door to a staff-only field.
 *
 * Authorization happens *before* anything reaches this class: the controller
 * applies `TicketPolicy::viewFull` to an attached ticket, and
 * {@see AssistantTicketLookup} applies the list visibility scopes. This class
 * decides *which fields* of an authorized record are sent, not *whether* the
 * record may be.
 *
 * The free text that remains (titles, descriptions, the question itself) is
 * then passed through {@see PromptRedactor} by {@see SafeAgentInvoker}, using
 * the names collected by {@see namesToRedact()}.
 */
class AssistantContext
{
    /**
     * Per-request memo of {@see counterpartNames()}, keyed by user id.
     *
     * @var array<int, list<string>>
     */
    private array $counterparts = [];

    public function __construct(private readonly TicketVisibility $visibility) {}

    /** How the role is described to the model. Unknown roles get no description. */
    private const ROLE_LABELS = [
        'administrator' => 'an IT administrator',
        'technician' => 'an IT technician',
        'teacher' => 'a teacher (a requester, not IT staff)',
    ];

    /**
     * Permissions worth telling the model about, as what the user can do. Only
     * these, and only when the user holds them — so the assistant can say "ask
     * an administrator to assign it" to someone who cannot, without ever being
     * handed the permission table.
     */
    private const CAPABILITIES = [
        'tickets.create' => 'report problems as tickets',
        'tickets.comment' => 'comment on tickets they can see',
        'tickets.update' => 'update the progress of tickets they work on',
        'tickets.assign' => 'assign tickets to technicians',
        'maintenance.view' => 'view maintenance work',
        'maintenance.complete' => 'complete maintenance work',
        'assets.view' => 'look up equipment records',
        'assets.update' => 'update equipment records',
        'inventory.view' => 'view spare-parts inventory',
        'knowledge.view' => 'read the knowledge base',
        'knowledge.create' => 'write knowledge-base articles',
        'reports.view' => 'view reports and analytics',
        'users.view' => 'manage user accounts',
        'system.settings.manage' => 'change system settings',
    ];

    /**
     * The user, as the model sees them: role and capabilities, nothing that
     * identifies them.
     */
    public function userBlock(User $user): string
    {
        $role = self::ROLE_LABELS[$user->role->slug ?? ''] ?? 'a staff member';

        $can = [];
        foreach (self::CAPABILITIES as $permission => $phrase) {
            if ($user->hasPermissionTo($permission)) {
                $can[] = $phrase;
            }
        }

        return "The user is {$role}.".($can === [] ? '' : "\nThey can: ".implode('; ', $can).'.');
    }

    /** The audience rule for the agent's instructions, from role alone. */
    public function audienceRule(User $user): string
    {
        return $this->isStaff($user)
            ? 'The user is IT staff: you may suggest more technical checks, but still nothing destructive.'
            : 'The user is a teacher, not a technician: suggest only simple, safe steps. Never tell them to open the computer, change firmware settings, run commands or install software.';
    }

    /**
     * An attached ticket — one the controller has already admitted through
     * `TicketPolicy::viewFull` for this user.
     */
    public function attachedTicket(User $user, Ticket $ticket): string
    {
        $ticket->loadMissing(['status', 'category']);

        return implode("\n", array_filter([
            "Reference: {$ticket->ticket_number}",
            "Title: {$ticket->title}",
            'Status: '.($ticket->statusRow()->name ?? 'unknown'),
            $ticket->category !== null ? "Category: {$ticket->category->name}" : null,
            "Description: {$ticket->description}",
            // Administrator-only, exactly as TicketDetailResource exposes it.
            $user->isAdministrator() && $ticket->ai_summary !== null ? "Earlier AI summary: {$ticket->ai_summary}" : null,
        ]));
    }

    /** One line per ticket the lookup admitted: reference, status, date, title. */
    public function ticketLine(Ticket $ticket): string
    {
        return sprintf(
            '- %s (%s, %s): %s',
            $ticket->ticket_number,
            $ticket->statusRow()->name ?? 'unknown status',
            $ticket->created_at?->toDateString() ?? 'unknown date',
            $ticket->title,
        );
    }

    /** Reference material is shown only to a user who may read the knowledge base. */
    public function mayUseKnowledge(User $user): bool
    {
        return $user->hasPermissionTo('knowledge.view');
    }

    /**
     * Every person known to be connected to what is in the prompt — the caller,
     * the attached ticket's reporter and technicians, and the reporters of the
     * listed tickets — so their names are removed from the free text they or
     * others wrote ("Ms Cruz's PC won't boot").
     *
     * @param  iterable<Ticket>  $tickets
     * @return list<string>
     */
    public function namesToRedact(User $user, ?Ticket $attached, iterable $tickets): array
    {
        $people = [$user];

        if ($attached !== null) {
            $attached->loadMissing(['reporter', 'assignedTechnician', 'assignments.technician']);
            $people[] = $attached->reporter;
            $people[] = $attached->assignedTechnician;

            foreach ($attached->assignments as $assignment) {
                $people[] = $assignment->technician;
            }
        }

        foreach ($tickets as $ticket) {
            $people[] = $ticket->reporter;
        }

        $names = [];
        foreach ($people as $person) {
            if ($person instanceof User) {
                $names[] = trim(implode(' ', array_filter([$person->first_name, $person->middle_name, $person->last_name])));
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    public function isStaff(User $user): bool
    {
        return $user->isAdministrator() || $user->role?->slug === 'technician';
    }

    /**
     * The other people around this user's work — reporters and technicians of
     * the tickets in their own scope — so their names can be removed from any
     * free text that mentions them (a notification title, an announcement, a
     * ticket title like "Ms Cruz's PC"). Bounded to the newest 200 tickets;
     * an administrator's scope is the whole system, which is why the tools the
     * administrator holds are built to carry no names in the first place.
     *
     * @return list<string>
     */
    public function counterpartNames(User $user): array
    {
        return $this->counterparts[$user->getKey()] ??= $this->loadCounterparts($user);
    }

    /** @return list<string> */
    private function loadCounterparts(User $user): array
    {
        $query = Ticket::query()
            ->select(['tickets.id', 'tickets.reporter_id', 'tickets.assigned_technician_id'])
            ->with(['reporter:id,first_name,middle_name,last_name', 'assignedTechnician:id,first_name,middle_name,last_name'])
            ->latest('tickets.id')
            ->limit(200);

        if ($user->isAdministrator()) {
            $this->visibility->scope($query, $user);
        } elseif ($user->role?->slug === 'technician') {
            $this->visibility->scopeAssigned($query, $user);
        } else {
            $this->visibility->scopeOwn($query, $user);
        }

        $names = [];
        foreach ($query->get() as $ticket) {
            foreach ([$ticket->reporter, $ticket->assignedTechnician] as $person) {
                if ($person instanceof User && $person->getKey() !== $user->getKey()) {
                    $names[] = trim(implode(' ', array_filter([$person->first_name, $person->middle_name, $person->last_name])));
                }
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}
