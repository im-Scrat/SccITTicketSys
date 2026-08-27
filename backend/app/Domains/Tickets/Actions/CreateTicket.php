<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Events\TicketCreated;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Domains\Tickets\Services\TicketNumberGenerator;
use App\Enums\ActivityAction;
use App\Enums\TicketSource;
use App\Enums\TicketUpdateType;
use App\Models\PcUnit;
use App\Models\Room;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Report a fault (SRS FR-TKT-001..004).
 *
 * Everything that makes a ticket *findable and answerable* is derived here
 * rather than asked of the reporter:
 *
 *  - the **status** is the seeded default (`Open`), never client-supplied;
 *  - the **priority** falls back to the category's `default_priority_id`, so a
 *    teacher who does not know what "P2" means still gets a sensible clock;
 *  - the **SLA deadlines** come from that priority (FR-TKT-004);
 *  - the **room** is inherited from the chosen PC unit when the reporter did not
 *    name one, because a machine already knows where it lives.
 *
 * The opening status history row is written in the same transaction, so a
 * ticket's timeline starts at creation rather than at its first change.
 *
 * A `TicketCreated` event is raised with **no listener in this phase** — it is
 * the seam the asynchronous AI analysis attaches to later (FR-AI-021), and
 * raising it now means that phase adds a listener instead of editing this class.
 */
class CreateTicket
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TicketLifecycle $lifecycle,
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, Request $request): Ticket
    {
        $category = TicketCategory::query()
            ->where('slug', (string) $data['category'])
            ->firstOrFail();

        $priority = $this->resolvePriority($data['priority'] ?? null, $category);
        $status = TicketStatus::query()->where('is_default', true)->firstOrFail();

        $pcUnit = $this->resolvePcUnit($data['pc_unit'] ?? null);
        $room = $this->resolveRoom($data['room'] ?? null) ?? $pcUnit?->room;

        // Technicians and administrators may file on behalf of a reporter
        // (FR-TKT-003); a teacher may only ever report as themselves, which the
        // FormRequest enforces before we get here.
        $reporter = $this->resolveReporter($data['reporter'] ?? null, $actor);

        $deadlines = $this->sla->deadlinesFor($priority);

        $ticket = DB::transaction(function () use (
            $data, $category, $priority, $status, $pcUnit, $room, $reporter, $actor, $deadlines
        ): Ticket {
            $ticket = Ticket::create([
                'ticket_number' => $this->numbers->next(),
                'reporter_id' => $reporter->getKey(),
                'room_id' => $room?->getKey(),
                'pc_unit_id' => $pcUnit?->getKey(),
                'category_id' => $category->getKey(),
                'priority_id' => $priority->getKey(),
                'current_status_id' => $status->getKey(),
                'title' => $data['title'],
                'description' => $data['description'],
                'source' => TicketSource::Web->value,
                ...$deadlines,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->attachTags($ticket, $data['tags'] ?? []);
            $this->lifecycle->recordInitial($ticket, $actor);

            TicketUpdate::query()->create([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $actor->getKey(),
                'update_type' => TicketUpdateType::System->value,
                'body' => 'Ticket reported.',
                'created_at' => now(),
            ]);

            return $ticket;
        });

        $ticket->load(['status', 'priority', 'category', 'reporter', 'pcUnit.room.floor.building', 'room.floor.building']);

        $this->audit->activity(
            ActivityAction::TicketCreated,
            actor: $actor,
            subject: $ticket,
            properties: [
                'ticket_number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'category' => $category->slug,
                'priority' => $priority->slug,
                'pc_unit' => $pcUnit?->unit_code,
                'room' => $room?->name,
                'on_behalf_of' => $reporter->getKey() !== $actor->getKey() ? $reporter->fullName() : null,
            ],
            request: $request,
            module: 'tickets',
            description: "Ticket {$ticket->ticket_number} reported",
        );

        // The AI seam. No listener in Phase 2.6 — see the class docblock.
        TicketCreated::dispatch($ticket);

        return $ticket;
    }

    /**
     * A requester rarely knows what priority a fault deserves, so the category's
     * default carries it. An administrator may still state one explicitly.
     */
    private function resolvePriority(mixed $slug, TicketCategory $category): TicketPriority
    {
        if (is_string($slug) && $slug !== '') {
            return TicketPriority::query()->where('slug', $slug)->firstOrFail();
        }

        if ($category->default_priority_id !== null) {
            $default = TicketPriority::query()->find($category->default_priority_id);

            if ($default !== null) {
                return $default;
            }
        }

        // Last resort: the middle of the scale, never the most urgent — an
        // unspecified fault is not automatically a crisis.
        return TicketPriority::query()->where('slug', 'medium')->firstOrFail();
    }

    private function resolvePcUnit(mixed $uuid): ?PcUnit
    {
        return is_string($uuid) && $uuid !== ''
            ? PcUnit::query()->where('uuid', $uuid)->first()
            : null;
    }

    private function resolveRoom(mixed $uuid): ?Room
    {
        return is_string($uuid) && $uuid !== ''
            ? Room::query()->where('uuid', $uuid)->first()
            : null;
    }

    private function resolveReporter(mixed $uuid, User $actor): User
    {
        if (! is_string($uuid) || $uuid === '') {
            return $actor;
        }

        return User::query()->where('uuid', $uuid)->firstOr(fn (): User => $actor);
    }

    /**
     * @param  array<int, string>|mixed  $slugs
     */
    private function attachTags(Ticket $ticket, mixed $slugs): void
    {
        if (! is_array($slugs) || $slugs === []) {
            return;
        }

        $ids = Tag::query()->whereIn('slug', $slugs)->pluck('id')->all();

        if ($ids !== []) {
            $ticket->tags()->sync($ids);
        }
    }
}
