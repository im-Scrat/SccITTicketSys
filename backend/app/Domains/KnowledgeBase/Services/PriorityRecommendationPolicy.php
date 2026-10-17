<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Jobs\AnalyzeTicketJob;
use App\Domains\Tickets\Services\SlaCalculator;
use App\Enums\ActivityAction;
use App\Enums\TicketPrioritySource;
use App\Enums\TicketUpdateType;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketUpdate;

/**
 * Phase 2.9 (owner decision D-B) — decides, in plain PHP, whether an AI
 * priority recommendation is *applied*. The model only ever recommends; this
 * class is the whole of the AI's authority over a ticket's priority.
 *
 * A recommendation raises the priority only when **every** condition holds:
 *
 *   1. an administrator switched `ai.priority_auto_raise` on (seeded off);
 *   2. a confidence threshold is configured and the analysis meets it
 *      (FR-AI-010 — no threshold set means no automatic action at all);
 *   3. the recommended level is strictly **higher** than the current one —
 *      it never lowers, so a room's priority floor (applied at creation) and a
 *      category default can only ever be exceeded, never undercut;
 *   4. no IT staff member set it (`priority_source` ≠ manual). A reporter's
 *      own choice (`reporter`, SDD DD-75) is not a lock: like a category
 *      default or a room floor, it may be raised;
 *   5. the ticket is still live (not resolved, closed or cancelled).
 *
 * When applied it moves the SLA clock exactly as a human change would
 * ({@see SlaCalculator::recalculate()}, anchored to creation), records a
 * `priority_change` timeline row with no human actor, and audits it. Called by
 * {@see AnalyzeTicketJob} inside its transaction, under the ticket row lock.
 */
class PriorityRecommendationPolicy
{
    /** The priority slugs the model may recommend — the seeded scale. */
    public const SLUGS = ['low', 'medium', 'high', 'critical'];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly SlaCalculator $sla,
        private readonly AuditLogger $audit,
    ) {}

    /** Did this recommendation pass every rule? Pure: changes nothing. */
    public function shouldApply(Ticket $ticket, ?TicketPriority $recommended, float $confidence): bool
    {
        if ($recommended === null || ! $this->settings->priorityAutoRaiseEnabled()) {
            return false;
        }

        $threshold = $this->settings->confidenceThreshold();

        if ($threshold === null || $confidence < $threshold) {
            return false;
        }

        if ($ticket->priority_source === TicketPrioritySource::Manual) {
            return false;
        }

        $ticket->loadMissing(['priority', 'status']);

        if ($ticket->priority === null || $recommended->level <= $ticket->priority->level) {
            return false;
        }

        return $ticket->status !== null
            && ! $ticket->status->is_terminal
            && $ticket->resolved_at === null;
    }

    /**
     * Apply the recommendation if — and only if — {@see shouldApply()} allows
     * it. Returns whether the priority changed.
     */
    public function applyIfAllowed(Ticket $ticket, ?TicketPriority $recommended, float $confidence, ?string $rationale): bool
    {
        if (! $this->shouldApply($ticket, $recommended, $confidence)) {
            return false;
        }

        /** @var TicketPriority $recommended (non-null: shouldApply() checked it) */
        $previous = $ticket->priority;
        $deadlines = $this->sla->recalculate($ticket, $recommended);

        $ticket->forceFill([
            'priority_id' => $recommended->getKey(),
            'priority_source' => TicketPrioritySource::Ai->value,
            ...$deadlines,
        ])->save();
        $ticket->setRelation('priority', $recommended);

        // No human actor: the timeline shows "System". The rationale stays in
        // metadata (staff-facing); the timeline projection never prints it to
        // a requester.
        TicketUpdate::query()->create([
            'ticket_id' => $ticket->getKey(),
            'user_id' => null,
            'update_type' => TicketUpdateType::PriorityChange->value,
            'body' => null,
            'metadata' => [
                'from' => $previous?->slug,
                'from_label' => $previous?->name,
                'to' => $recommended->slug,
                'to_label' => $recommended->name,
                'source' => TicketPrioritySource::Ai->value,
                'confidence' => $confidence,
                'rationale' => $rationale,
            ],
            'created_at' => now(),
        ]);

        $this->audit->activity(
            ActivityAction::TicketPriorityChanged,
            actor: null,
            subject: $ticket,
            properties: [
                'from' => $previous?->slug,
                'to' => $recommended->slug,
                'source' => TicketPrioritySource::Ai->value,
                'confidence' => $confidence,
                'resolution_due_at' => $deadlines['resolution_due_at']?->toIso8601String(),
            ],
            module: 'knowledge_base',
            description: sprintf(
                'AI raised ticket %s priority from %s to %s',
                $ticket->ticket_number,
                $previous !== null ? $previous->name : 'none',
                $recommended->name,
            ),
        );

        return true;
    }
}
