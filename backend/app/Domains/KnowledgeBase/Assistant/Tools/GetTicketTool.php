<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Domains\Tickets\Services\TicketTimeline;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * One ticket in full — only when `TicketPolicy::viewFull` admits it for this
 * user. Unknown and forbidden answer identically, so the tool cannot be used
 * to probe which references exist.
 */
final class GetTicketTool extends AssistantTool
{
    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly TicketVisibility $visibility,
        private readonly TicketTimeline $timeline,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'get_ticket';
    }

    public function description(): string
    {
        return 'Full details of one ticket the current user is allowed to see, by its reference (e.g. TKT-2026-00005): '
            .'status, priority, category, room, machine, description, what has happened so far, and recent comments.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->max(40)->description('The ticket reference, like TKT-2026-00005.')->required(),
        ];
    }

    protected function run(Request $request): string
    {
        $args = $request->validate(['reference' => ['required', 'string', 'max:40']]);
        $reference = trim((string) $args['reference']);

        $ticket = Ticket::query()
            ->with(['status', 'priority', 'category', 'room', 'pcUnit', 'reporter', 'assignedTechnician'])
            ->where(fn ($q) => $q->whereRaw('upper(ticket_number) = ?', [strtoupper($reference)])
                ->when(Str::isUuid($reference), fn ($u) => $u->orWhere('uuid', $reference)))
            ->first();

        // The policy, not a role check: the same ability the ticket page uses.
        if ($ticket === null || ! $this->user->can('viewFull', $ticket)) {
            return 'No ticket with that reference is available to you.';
        }

        $this->noteName($ticket->reporter);
        $this->noteName($ticket->assignedTechnician);

        // "Yours" by either record: the directory's assigned_technician_id or
        // an assignment row — a technician's access is defined by the latter
        // (TicketVisibility), so that is what "assigned to you" must follow.
        $mine = $ticket->assigned_technician_id === $this->user->getKey()
            || $ticket->assignments()->where('technician_id', $this->user->getKey())->exists();
        $someone = $ticket->assigned_technician_id !== null || $ticket->assignments()->exists();

        $lines = [
            "Reference: {$ticket->ticket_number}",
            "Title: {$ticket->title}",
            "Status: {$ticket->status->name}",
            "Priority: {$ticket->priority->name}",
            "Category: {$ticket->category->name}",
            'Reported: '.$this->date($ticket->created_at),
            $ticket->room !== null ? "Room: {$ticket->room->name}" : null,
            $ticket->pcUnit !== null ? "Machine: {$ticket->pcUnit->pc_name} ({$ticket->pcUnit->unit_code})" : null,
            $ticket->reporter_id === $this->user->getKey() ? 'Reported by: you' : null,
            $mine ? 'Assigned to: you' : ($someone ? 'Assigned to: a technician' : 'Assigned to: nobody yet'),
            $ticket->resolved_at !== null ? 'Resolved: '.$this->date($ticket->resolved_at) : null,
            $ticket->closed_at !== null ? 'Closed: '.$this->date($ticket->closed_at) : null,
            'Description: '.$this->excerpt($ticket->description, 1200),
            // Administrator-only, exactly as TicketDetailResource exposes it.
            $this->isAdministrator() && $ticket->ai_summary !== null ? 'AI pre-screening summary: '.$this->excerpt($ticket->ai_summary, 600) : null,
        ];

        $history = $this->timeline->for($ticket, $this->user);

        if ($history !== []) {
            $lines[] = 'History:';
            foreach (array_slice($history, -12) as $entry) {
                $lines[] = $this->historyLine($entry);
            }
        }

        $comments = TicketComment::query()
            ->where('ticket_id', $ticket->getKey())
            ->when(! $this->visibility->canSeeInternal($this->user, $ticket), fn ($q) => $q->where('is_internal', false))
            ->with('user:id,first_name,middle_name,last_name,role_id')
            ->latest('id')
            ->limit(8)
            ->get()
            ->reverse();

        if ($comments->isNotEmpty()) {
            $lines[] = 'Recent comments:';
            foreach ($comments as $comment) {
                $this->noteName($comment->user);
                $who = $comment->user_id === $this->user->getKey() ? 'you' : ($comment->user?->role->slug ?? 'someone');
                $lines[] = sprintf('- %s (%s%s): %s', $this->date($comment->created_at), $who, $comment->is_internal ? ', internal note' : '', $this->excerpt($comment->body, 300));
            }
        }

        return implode("\n", array_filter($lines, fn (?string $l): bool => $l !== null));
    }

    /** @param  array<string, mixed>  $entry */
    private function historyLine(array $entry): string
    {
        $at = is_string($entry['at'] ?? null) ? substr($entry['at'], 0, 16) : '';
        $what = match ($entry['kind'] ?? '') {
            'created' => 'ticket created',
            'status' => sprintf('status %s → %s', $entry['from'] ?? '?', $entry['to'] ?? '?'),
            'priority' => sprintf('priority %s → %s', $entry['from'] ?? '?', $entry['to'] ?? '?'),
            'outcome' => ($entry['outcome'] ?? '') === 'not_fixed' ? 'reporter said the AI suggestions did not fix it' : 'reporter said the AI suggestions fixed it',
            'assignment' => ! empty($entry['reassigned']) ? 'reassigned to a technician' : 'assigned to a technician',
            'assignment_response' => 'technician responded: '.($entry['assignment_status'] ?? 'updated'),
            default => (string) ($entry['kind'] ?? 'update'),
        };

        return "- {$at} {$what}";
    }
}
