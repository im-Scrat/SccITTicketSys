<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Events\TicketCreated;
use App\Domains\Tickets\Http\Requests\StoreTicketRequest;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Domains\Tickets\Services\TicketLifecycle;
use App\Domains\Tickets\Services\TicketNumberGenerator;
use App\Enums\ActivityAction;
use App\Enums\TicketPrioritySource;
use App\Enums\TicketSource;
use App\Enums\TicketUpdateType;
use App\Models\Asset;
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
use Illuminate\Support\Str;

/**
 * Report a fault (SRS FR-TKT-001..004).
 *
 * Everything that makes a ticket *findable and answerable* is derived here
 * rather than trusted from the reporter:
 *
 *  - the **status** is the seeded default (`Open`), never client-supplied;
 *  - the **priority** follows a fixed order of authority (owner decision D-B,
 *    extended by SDD DD-75) — see {@see resolvePriority()} — and
 *    `priority_source` records which rule decided it;
 *  - the **SLA deadlines** come from that priority (FR-TKT-004);
 *  - the **room** is inherited from the chosen PC unit — or, since Phase 2.9,
 *    the chosen asset (SDD DD-79) — when the reporter did not name one, because
 *    a machine already knows where it lives. Validation has already required a
 *    named item to be in a named room, so the stored room is the authoritative
 *    answer for every later room rule;
 *  - the **"Other" description** (`category_detail`, SDD DD-77) is stored only
 *    under a category that asks for one, trimmed.
 *
 * The opening status history row is written in the same transaction, so a
 * ticket's timeline starts at creation rather than at its first change.
 *
 * `TicketCreated` is dispatched after that transaction commits. It was raised
 * from Phase 2.6 as a seam with no listener; it now has two — KnowledgeBase's
 * `AnalyzeTicketOnCreated` queues the AI pre-screening (FR-AI-021, WP-I) and
 * Analytics' `SignalAdminDashboard` pushes the administrator dashboard's
 * realtime signal — and both attached without editing this class, which was
 * the point.
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

        $status = TicketStatus::query()->where('is_default', true)->firstOrFail();

        $pcUnit = $this->resolvePcUnit($data['pc_unit'] ?? null);
        $asset = $this->resolveAsset($data['asset'] ?? null);
        $room = $this->resolveRoom($data['room'] ?? null) ?? $pcUnit->room ?? $asset?->currentRoom;

        $detail = $category->requires_detail ? $this->cleanDetail($data['category_detail'] ?? null) : null;

        [$priority, $prioritySource, $requested] = $this->resolvePriority($data['priority'] ?? null, $category, $room, $actor);

        // Technicians and administrators may file on behalf of a reporter
        // (FR-TKT-003); a teacher may only ever report as themselves, which the
        // FormRequest enforces before we get here.
        $reporter = $this->resolveReporter($data['reporter'] ?? null, $actor);

        $deadlines = $this->sla->deadlinesFor($priority);

        $ticket = DB::transaction(function () use (
            $data, $category, $detail, $priority, $prioritySource, $status, $pcUnit, $asset, $room, $reporter, $actor, $deadlines
        ): Ticket {
            $ticket = Ticket::create([
                'ticket_number' => $this->numbers->next(),
                'reporter_id' => $reporter->getKey(),
                'room_id' => $room?->getKey(),
                'pc_unit_id' => $pcUnit?->getKey(),
                'asset_id' => $asset?->getKey(),
                'category_id' => $category->getKey(),
                'category_detail' => $detail,
                'priority_id' => $priority->getKey(),
                'priority_source' => $prioritySource->value,
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

        $ticket->load([
            'status', 'priority', 'category', 'reporter',
            'pcUnit.room.floor.building', 'room.floor.building',
            // With trashed, as every ticket projection reads it
            // (`TicketDirectoryQuery::base()`): equipment bought under an
            // archived catalog entry is still reportable, and keeps its name.
            'asset.hardwareModel' => fn ($query) => $query->withTrashed(),
        ]);

        $this->audit->activity(
            ActivityAction::TicketCreated,
            actor: $actor,
            subject: $ticket,
            properties: [
                'ticket_number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'category' => $category->slug,
                'priority' => $priority->slug,
                'priority_source' => $prioritySource->value,
                // What the reporter asked for, when a room's floor overrode it —
                // the ticket itself only remembers the outcome.
                ...($requested !== null ? ['requested_priority' => $requested->slug] : []),
                'pc_unit' => $pcUnit?->unit_code,
                'asset' => $asset?->asset_tag,
                'room' => $room?->name,
                'on_behalf_of' => $reporter->getKey() !== $actor->getKey() ? $reporter->fullName() : null,
            ],
            request: $request,
            module: 'tickets',
            description: "Ticket {$ticket->ticket_number} reported",
        );

        // Dispatched after the transaction above has committed — see the class
        // docblock for what listens.
        TicketCreated::dispatch($ticket);

        return $ticket;
    }

    /**
     * The priority, why it is that, and — when a room floor overrode the
     * reporter — what the reporter asked for.
     *
     * The order of authority, weakest first (SDD DD-75):
     *
     *   category default  <  reporter's choice  <  room floor  <  AI  <  IT staff
     *
     *  - **IT staff** (administrator or technician) choosing explicitly: kept
     *    exactly as chosen and recorded as `manual`. The person filing can see
     *    the room, and a staff decision outranks every automatic rule, the floor
     *    included. It is the only source the AI never raises.
     *  - **A reporter** choosing: recorded as `reporter`, then raised — never
     *    lowered — to the room's priority floor (`location`) when the floor is
     *    higher, because the administrator's minimum for that room still holds.
     *    The AI may raise it later.
     *  - **Nobody** choosing ("Not sure — let the IT team decide"): the
     *    category's default, raised to the room's floor — Phase 2.9's D-B rule,
     *    unchanged.
     *
     * @return array{TicketPriority, TicketPrioritySource, TicketPriority|null}
     */
    private function resolvePriority(mixed $slug, TicketCategory $category, ?Room $room, User $actor): array
    {
        $floor = $room?->priorityFloor;

        if (is_string($slug) && $slug !== '') {
            $chosen = TicketPriority::query()->where('slug', $slug)->firstOrFail();

            if ($actor->isAdministrator() || $actor->isTechnician()) {
                return [$chosen, TicketPrioritySource::Manual, null];
            }

            if ($floor !== null && $floor->level > $chosen->level) {
                return [$floor, TicketPrioritySource::Location, $chosen];
            }

            return [$chosen, TicketPrioritySource::Reporter, null];
        }

        $derived = $this->categoryPriority($category);

        if ($floor !== null && $floor->level > $derived->level) {
            return [$floor, TicketPrioritySource::Location, null];
        }

        return [$derived, TicketPrioritySource::Category, null];
    }

    private function categoryPriority(TicketCategory $category): TicketPriority
    {
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

    /**
     * The trimmed description, or null. `Str::trim` rather than PHP's `trim()`:
     * it is the Unicode-aware trim the HTTP middleware applies, so a caller that
     * is not an HTTP request (tinker, a command) cannot store a value — a lone
     * form feed, say — that `tickets_category_detail_check` counts as blank.
     */
    private function cleanDetail(mixed $value): ?string
    {
        $value = is_string($value) ? Str::trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function resolvePcUnit(mixed $uuid): ?PcUnit
    {
        return is_string($uuid) && $uuid !== ''
            ? PcUnit::query()->where('uuid', $uuid)->first()
            : null;
    }

    /**
     * Which asset is reportable was decided by {@see StoreTicketRequest}; this
     * only loads it, with the room the ticket may inherit.
     */
    private function resolveAsset(mixed $uuid): ?Asset
    {
        return is_string($uuid) && $uuid !== ''
            ? Asset::query()->with('currentRoom')->where('uuid', $uuid)->first()
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
