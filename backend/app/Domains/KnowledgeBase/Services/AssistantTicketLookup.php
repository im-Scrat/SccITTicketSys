<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Tickets\Services\TicketVisibility;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The **authorization-scoped transactional retrieval** half of the assistant
 * (WP-Q; SDD DD-75) — which of a person's own tickets the assistant may look at
 * when answering them.
 *
 * ── Why this is a lookup and not a second vector index ─────────────────────
 *
 * DD-71 decided that tickets, comments and maintenance records are **never**
 * embedded: one shared index over them would be a second, unscoped route to text
 * that their own services visibility-scope row by row. That decision stands. So
 * "the assistant knows about my ticket" is done the other way round — a plain
 * full-text lookup over `tickets`, constrained by the **same**
 * {@see TicketVisibility} scopes the list endpoints use, at query time, per
 * caller:
 *
 *   administrator   every ticket (the administrative scope)
 *   technician      tickets they hold a readable assignment on
 *   requester       **only tickets they reported** — not the community feed
 *
 * A ticket absent from a user's list is therefore absent from what the assistant
 * can see, and there is no index whose contents could outlive a permission
 * change or a reassignment. Retrieval of *knowledge* (Source A) is a separate
 * path, `KnowledgeRetriever`, and cannot return a ticket by construction.
 *
 * What the assistant is shown is deliberately thin — reference, title, status,
 * category, date. The one exception is a ticket the caller **explicitly attaches**
 * and that `TicketPolicy::viewFull` admits, which adds its description.
 */
class AssistantTicketLookup
{
    public function __construct(private readonly TicketVisibility $visibility) {}

    /**
     * Tickets relevant to a question, inside the caller's own visibility.
     *
     * @return Collection<int, Ticket>
     */
    public function relevantTo(User $user, string $message, int $limit = 3): Collection
    {
        $message = trim($message);

        if ($message === '' || ! $user->hasPermissionTo('tickets.view')) {
            // Deny by default, and never "recent tickets" for an empty question.
            return new Collection;
        }

        $query = Ticket::query()
            ->with(['status:id,name', 'category:id,name'])
            ->select(['tickets.id', 'tickets.uuid', 'tickets.ticket_number', 'tickets.title', 'tickets.current_status_id', 'tickets.category_id', 'tickets.created_at']);

        if ($user->isAdministrator()) {
            $this->visibility->scope($query, $user);
        } elseif ($user->role?->slug === 'technician') {
            $this->visibility->scopeAssigned($query, $user);
        } else {
            $this->visibility->scopeOwn($query, $user);
        }

        return $query
            ->whereRaw("tickets.search_vector @@ websearch_to_tsquery('english', ?)", [$message])
            ->orderByRaw("ts_rank(tickets.search_vector, websearch_to_tsquery('english', ?)) desc", [$message])
            ->limit($limit)
            ->get();
    }
}
