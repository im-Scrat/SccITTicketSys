<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Assistant\Tools;

use App\Domains\KnowledgeBase\Assistant\AssistantTool;
use App\Domains\KnowledgeBase\Services\AssistantContext;
use App\Domains\KnowledgeBase\Services\PromptRedactor;
use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Tools\Request;

/**
 * Count and list the tickets this user may see — the same rows as their own
 * ticket list (SRS FR-AI-004; DD-40 row scoping).
 *
 *   requester     only tickets they reported — never the community feed
 *   technician    tickets they hold an assignment on
 *   administrator every ticket
 */
final class SearchTicketsTool extends AssistantTool
{
    private const MAX_ROWS = 20;

    public function __construct(
        User $user,
        PromptRedactor $redactor,
        AssistantContext $context,
        private readonly TicketVisibility $visibility,
    ) {
        parent::__construct($user, $redactor, $context);
    }

    public function name(): string
    {
        return 'search_tickets';
    }

    public function description(): string
    {
        return 'Count and list the tickets the current user can see (their own reported tickets for a requester, '
            .'their assigned tickets for a technician, all tickets for an administrator). Returns totals, a '
            .'breakdown by status, and the newest matching tickets with reference, status, date and title. '
            .'Use it for any question like "how many tickets have I reported", "what is open", "my tickets this month".';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $slugs = TicketStatus::query()->orderBy('sort_order')->pluck('slug')->all();

        return [
            'status' => $schema->string()->enum($slugs)->description('Only tickets currently in this status.'),
            'text' => $schema->string()->max(200)->description('Words to look for in the title or description.'),
            'days' => $schema->integer()->min(1)->max(3650)->description('Only tickets created within the last N days.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->description('How many tickets to list (default 10).'),
        ];
    }

    protected function run(Request $request): string
    {
        if (! $this->user->hasPermissionTo('tickets.view')) {
            return 'You do not have access to tickets.';
        }

        $args = $request->validate([
            'status' => ['nullable', 'string', 'max:60'],
            'text' => ['nullable', 'string', 'max:200'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ROWS],
        ]);

        $query = $this->scoped(Ticket::query());

        if (! empty($args['status'])) {
            $query->whereHas('status', fn (Builder $s) => $s->where('slug', $args['status']));
        }

        if (! empty($args['days'])) {
            $query->where('tickets.created_at', '>=', now()->subDays((int) $args['days']));
        }

        if (! empty($args['text'])) {
            $query->whereRaw("tickets.search_vector @@ websearch_to_tsquery('english', ?)", [trim((string) $args['text'])]);
        }

        $total = (clone $query)->count();

        $byStatus = (clone $query)
            ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
            ->groupBy('ticket_statuses.name', 'ticket_statuses.sort_order')
            ->orderBy('ticket_statuses.sort_order')
            ->selectRaw('ticket_statuses.name as status_name, count(*) as aggregate')
            ->pluck('aggregate', 'status_name');

        $rows = (clone $query)
            ->with(['status:id,name', 'room:id,name', 'pcUnit:id,pc_name', 'reporter:id,first_name,middle_name,last_name', 'assignedTechnician:id,first_name,middle_name,last_name'])
            ->latest('tickets.created_at')
            ->limit((int) ($args['limit'] ?? 10))
            ->get();

        $lines = [
            sprintf('Scope: %s — %d total.', $this->scopeLabel(), $total),
        ];

        if ($byStatus->isNotEmpty()) {
            $lines[] = 'By status: '.$byStatus->map(fn ($n, $name) => "{$name} {$n}")->implode('; ').'.';
        }

        if ($rows->isEmpty()) {
            $lines[] = 'No tickets match.';

            return implode("\n", $lines);
        }

        $lines[] = sprintf('Newest %d:', $rows->count());

        foreach ($rows as $ticket) {
            $this->noteName($ticket->reporter);
            $this->noteName($ticket->assignedTechnician);

            $lines[] = sprintf(
                '- %s · %s · %s · %s%s%s',
                $ticket->ticket_number,
                $ticket->status->name,
                $this->date($ticket->created_at),
                $ticket->title,
                $ticket->room !== null ? " · Room {$ticket->room->name}" : '',
                $ticket->pcUnit !== null ? " · {$ticket->pcUnit->pc_name}" : '',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private function scoped(Builder $query): Builder
    {
        if ($this->isAdministrator()) {
            return $this->visibility->scope($query, $this->user);
        }

        if ($this->isTechnician()) {
            return $this->visibility->scopeAssigned($query, $this->user);
        }

        return $this->visibility->scopeOwn($query, $this->user);
    }

    private function scopeLabel(): string
    {
        return match (true) {
            $this->isAdministrator() => 'all tickets in the system',
            $this->isTechnician() => 'tickets assigned to you',
            default => 'tickets you reported',
        };
    }
}
