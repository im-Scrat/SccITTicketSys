<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Enums\ActivityAction;
use App\Enums\TicketPrioritySource;
use App\Enums\TicketUpdateType;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketPriority;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edit a ticket's attributes, and — separately — its priority and duplicate link
 * (SRS FR-TKT-001/004/011).
 *
 * **`status` and `assigned_technician_id` are not accepted here**, and that is
 * deliberate: each has its own audited write path (`ChangeTicketStatus` →
 * `TicketLifecycle`, `AssignTicket` → `technician_assignments`). Letting an
 * ordinary edit set them would allow a status to move without a
 * `ticket_status_history` row, which is exactly the guarantee FR-TKT-005 exists
 * to make. The FormRequest refuses them outright rather than ignoring them, so
 * the API never quietly does less than the caller asked.
 *
 * Changing the **priority** re-derives the SLA deadlines from the new budget,
 * anchored to when the fault was reported — escalating a stale ticket tightens
 * its clock rather than resetting it.
 */
class UpdateTicket
{
    /** @var list<string> */
    private const SCALAR_FIELDS = ['title', 'description'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SlaCalculator $sla,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Ticket $ticket, array $data, User $actor, Request $request): Ticket
    {
        $ticket->loadMissing(['category', 'priority']);

        $changes = [];
        $attributes = [];

        foreach (self::SCALAR_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $new = (string) $data[$field];

            if ($new !== (string) $ticket->{$field}) {
                $changes[$field] = ['from' => $ticket->{$field}, 'to' => $new];
            }

            $attributes[$field] = $new;
        }

        $effectiveCategory = $ticket->category;

        if (array_key_exists('category', $data)) {
            $category = TicketCategory::query()->where('slug', (string) $data['category'])->first();

            if ($category !== null && $category->getKey() !== $ticket->category_id) {
                $changes['category'] = ['from' => $ticket->category?->name, 'to' => $category->name];
                $attributes['category_id'] = $category->getKey();
                $effectiveCategory = $category;
            }
        }

        // Phase 2.9 (SDD DD-77). Resolved here, in the action, and added to
        // `$attributes` like any other field — validation alone is not enough,
        // because an edit whose `$attributes` stay empty returns below without
        // saving, so a description-only edit would be silently dropped.
        $detail = $this->categoryDetail($ticket, $effectiveCategory, $data);

        if ($detail !== $ticket->category_detail) {
            $changes['category_detail'] = ['from' => $ticket->category_detail, 'to' => $detail];
            $attributes['category_detail'] = $detail;
        }

        if ($attributes === []) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $attributes, $actor, $data): void {
            $ticket->fill([...$attributes, 'updated_by' => $actor->getKey()])->save();

            if (array_key_exists('tags', $data) && is_array($data['tags'])) {
                $ticket->tags()->sync(Tag::query()->whereIn('slug', $data['tags'])->pluck('id')->all());
            }
        });

        $this->audit->activity(
            ActivityAction::TicketUpdated,
            actor: $actor,
            subject: $ticket,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'tickets',
            description: "Ticket {$ticket->ticket_number} updated",
        );

        return $ticket->refresh()->load(['status', 'priority', 'category', 'reporter']);
    }

    /**
     * The "Other" description this ticket should carry after the edit.
     *
     *  - Under a category that does not ask for one, none — so moving a ticket
     *    out of an "Other" category drops the description that only made sense
     *    there.
     *  - Otherwise the value sent (trimmed with `Str::trim`, the same Unicode
     *    trim the HTTP middleware applies, so a non-HTTP caller cannot store a
     *    blank that `tickets_category_detail_check` would refuse), or the
     *    current one when the field was not sent.
     *
     * Whether a null is *allowed* here is `UpdateTicketRequest`'s decision.
     *
     * @param  array<string, mixed>  $data
     */
    private function categoryDetail(Ticket $ticket, ?TicketCategory $category, array $data): ?string
    {
        if ($category !== null && ! $category->requires_detail) {
            return null;
        }

        if (! array_key_exists('category_detail', $data)) {
            return $ticket->category_detail;
        }

        $value = is_string($data['category_detail']) ? Str::trim($data['category_detail']) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Set or override the priority, re-deriving the SLA deadlines (FR-TKT-004).
     */
    public function changePriority(
        Ticket $ticket,
        TicketPriority $priority,
        User $actor,
        Request $request,
        ?string $reason = null,
    ): Ticket {
        $ticket->loadMissing('priority');
        $previous = $ticket->priority;

        if ($previous !== null && $previous->getKey() === $priority->getKey()) {
            return $ticket;
        }

        $deadlines = $this->sla->recalculate($ticket, $priority);

        DB::transaction(function () use ($ticket, $priority, $deadlines, $actor, $previous, $reason): void {
            $ticket->forceFill([
                'priority_id' => $priority->getKey(),
                // Phase 2.9: a human's explicit choice locks the priority
                // against the AI, which only ever raises non-manual ones.
                'priority_source' => TicketPrioritySource::Manual->value,
                ...$deadlines,
                'updated_by' => $actor->getKey(),
            ])->save();

            TicketUpdate::query()->create([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $actor->getKey(),
                'update_type' => TicketUpdateType::PriorityChange->value,
                'body' => $reason,
                'metadata' => [
                    'from' => $previous?->slug,
                    'from_label' => $previous?->name,
                    'to' => $priority->slug,
                    'to_label' => $priority->name,
                ],
                'created_at' => now(),
            ]);
        });

        $this->audit->activity(
            ActivityAction::TicketPriorityChanged,
            actor: $actor,
            subject: $ticket,
            properties: [
                'from' => $previous?->slug,
                'to' => $priority->slug,
                'reason' => $reason,
                'resolution_due_at' => $deadlines['resolution_due_at']?->toIso8601String(),
            ],
            request: $request,
            module: 'tickets',
            description: sprintf(
                'Ticket %s priority %s → %s',
                $ticket->ticket_number,
                $previous instanceof TicketPriority ? $previous->name : 'unset',
                $priority->name,
            ),
        );

        return $ticket->refresh()->load(['status', 'priority', 'category']);
    }

    /**
     * Link this ticket to the canonical one it duplicates (FR-TKT-011).
     *
     * The database's `tickets_no_self_duplicate_check` prevents self-reference;
     * this additionally refuses a two-step cycle, which the CHECK cannot see.
     */
    public function markDuplicate(
        Ticket $ticket,
        ?Ticket $canonical,
        User $actor,
        Request $request,
    ): Ticket {
        if ($canonical !== null && $canonical->duplicate_of_id === $ticket->getKey()) {
            throw ValidationException::withMessages([
                'duplicate_of' => 'That ticket is already marked as a duplicate of this one.',
            ]);
        }

        $ticket->forceFill([
            'duplicate_of_id' => $canonical?->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->audit->activity(
            ActivityAction::TicketMarkedDuplicate,
            actor: $actor,
            subject: $ticket,
            properties: [
                'duplicate_of' => $canonical?->ticket_number,
                'cleared' => $canonical === null,
            ],
            request: $request,
            module: 'tickets',
            description: $canonical !== null
                ? "Ticket {$ticket->ticket_number} marked a duplicate of {$canonical->ticket_number}"
                : "Ticket {$ticket->ticket_number} no longer marked a duplicate",
        );

        return $ticket->refresh()->load('duplicateOf');
    }
}
