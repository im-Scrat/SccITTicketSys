<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Services;

use App\Enums\TicketUpdateType;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;

/**
 * A ticket's history, as one viewer may read it (Phase 2.9, FR-TKT-012).
 *
 * Built from `ticket_updates` — already the append-only activity feed every
 * lifecycle, priority, assignment and reporter-outcome write lands in — so the
 * timeline needs no table of its own and cannot drift from what happened.
 * Comment rows are left out: comments have their own tab, with their own
 * internal-note rule.
 *
 * Callers must have passed `viewFull` first. Inside that, there are two
 * projections, chosen by {@see TicketVisibility::canSeeInternal()} — the same
 * line that decides who reads internal notes:
 *
 *   staff     Administrator, or a technician connected by assignment: actor
 *             names, the remarks typed with each change, the technician named
 *             on an assignment.
 *   reporter  Structural facts only — what changed and when. A remark is shown
 *             only when the viewer wrote it themselves; another person's words
 *             and every staff identity are withheld ("IT team", "A technician").
 *             Assignment-response rows (accept / decline / hold) are omitted:
 *             the status change they cause already tells the reporter what
 *             they need, and a decline is staff business.
 *
 * Nothing here returns a database id; entries carry no identifier at all.
 */
class TicketTimeline
{
    /** History is short; this is a guard, not a page size. */
    private const MAX_ENTRIES = 200;

    public function __construct(private readonly TicketVisibility $visibility) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Ticket $ticket, User $viewer): array
    {
        $staff = $this->visibility->canSeeInternal($viewer, $ticket);

        $updates = TicketUpdate::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('update_type', '!=', TicketUpdateType::Comment->value)
            ->with('user.role')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_ENTRIES)
            ->get();

        $entries = [];

        foreach ($updates as $update) {
            $entry = $this->entry($update, $viewer, $staff);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(TicketUpdate $update, User $viewer, bool $staff): ?array
    {
        $meta = $update->metadata ?? [];
        $own = $update->user_id !== null && $update->user_id === $viewer->getKey();

        $base = [
            'at' => $update->created_at?->toIso8601String(),
            'actor' => $this->actorLabel($update, $own, $staff),
            // Free text a person typed: staff read everyone's, a reporter only
            // their own (the content rule notifications already follow).
            'note' => ($staff || $own) ? $update->body : null,
        ];

        return match ($update->update_type) {
            TicketUpdateType::System => $base + ['kind' => 'created'],

            TicketUpdateType::StatusChange => $base + [
                'kind' => 'status',
                'from' => $meta['from_label'] ?? null,
                'to' => $meta['to_label'] ?? null,
            ],

            TicketUpdateType::PriorityChange => $base + [
                'kind' => 'priority',
                'from' => $meta['from_label'] ?? null,
                'to' => $meta['to_label'] ?? null,
            ],

            // WP-J: the reporter's FIXED (a status move tagged ai_analysis) or
            // NOT FIXED (no status move) after trying the AI's suggestions.
            TicketUpdateType::AiAnalysis => $base + [
                'kind' => 'outcome',
                'outcome' => ($meta['outcome'] ?? null) === 'not_fixed' ? 'not_fixed' : 'fixed',
                'from' => $meta['from_label'] ?? null,
                'to' => $meta['to_label'] ?? null,
            ],

            TicketUpdateType::Assignment => $this->assignmentEntry($base, $meta, $staff),

            TicketUpdateType::Comment => null,
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function assignmentEntry(array $base, array $meta, bool $staff): ?array
    {
        // A technician's response (accept/decline/hold/…) carries
        // `assignment_status`; an assignment made by an administrator does not.
        $isResponse = array_key_exists('assignment_status', $meta);

        if (! $staff) {
            return $isResponse ? null : $base + [
                'kind' => 'assignment',
                'reassigned' => (bool) ($meta['reassigned'] ?? false),
                'technician' => null,
            ];
        }

        return $base + [
            'kind' => $isResponse ? 'assignment_response' : 'assignment',
            'reassigned' => (bool) ($meta['reassigned'] ?? false),
            'technician' => $meta['technician'] ?? null,
            'assignment_status' => $meta['assignment_status'] ?? null,
        ];
    }

    private function actorLabel(TicketUpdate $update, bool $own, bool $staff): string
    {
        if ($own) {
            return 'You';
        }

        $actor = $update->user;

        if ($actor === null) {
            return 'System';
        }

        if ($staff) {
            return $actor->fullName();
        }

        return $actor->isAdministrator() || $actor->isTechnician() ? 'IT team' : 'Someone else';
    }
}
