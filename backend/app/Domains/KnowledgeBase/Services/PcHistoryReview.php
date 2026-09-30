<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\CompletedRepair;
use App\Domains\KnowledgeBase\Http\Resources\AiPredictionDetailResource;
use App\Models\PcUnit;

/**
 * The machine's repair history **as it stands now**, shaped for the
 * administrator's review screen (WP-M).
 *
 * ── Why this is separate from the finding's evidence ───────────────────────
 *
 * A finding's `evidence` is frozen at generation: it is what the model was
 * shown, and {@see AiPredictionDetailResource}
 * deliberately serves it unchanged so that a later repair cannot quietly
 * rewrite what justified a finding already made. That is the right rule for the
 * *reasoning* — and the wrong one for an administrator deciding whether the
 * finding still matters. So the two are shown side by side and labelled as what
 * they are: the evidence is "what this was based on", this is "the machine
 * today". A repair completed the day after a finding was filed is visible here
 * and, correctly, absent from the evidence.
 *
 * ── Read through the one authoritative history ─────────────────────────────
 *
 * {@see PcMaintenanceHistory} — completed `maintenance_records` only, never the
 * activity log — so this cannot disagree with the pipeline about what happened.
 *
 * ── Structural facts only ──────────────────────────────────────────────────
 *
 * Type, date, the ticket's *category* and the component types replaced. A
 * technician's diagnosis, root cause, resolution and notes, and the linked
 * ticket's title, are people's free text and stay on the maintenance record,
 * where that record's own authorization decides who may read them — copying
 * them across onto this surface would put them in front of an audience that
 * record never had.
 */
class PcHistoryReview
{
    /** Repairs listed under "previous problems", after the most recent one. */
    private const PREVIOUS_LIMIT = 5;

    public function __construct(private readonly PcMaintenanceHistory $history) {}

    /**
     * @return array{
     *     completed_repairs: int,
     *     corrective_repairs: int,
     *     recent_repair: array<string, mixed>|null,
     *     previous_problems: list<array<string, mixed>>
     * }
     */
    public function for(PcUnit $pcUnit): array
    {
        $snapshot = $this->history->for($pcUnit);

        // Newest first: the reader wants "what happened last" before "what
        // happened before that".
        $corrective = array_reverse($snapshot->correctiveRepairs());

        return [
            'completed_repairs' => count($snapshot->repairs),
            'corrective_repairs' => count($corrective),
            'recent_repair' => $corrective === [] ? null : $this->present($corrective[0]),
            'previous_problems' => array_map(
                fn (CompletedRepair $repair): array => $this->present($repair),
                array_slice($corrective, 1, self::PREVIOUS_LIMIT),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CompletedRepair $repair): array
    {
        return [
            'id' => $repair->uuid,
            'type' => $repair->typeName,
            'completed_at' => $repair->completedAt->toIso8601String(),
            'category' => $repair->ticketCategory,
            'components' => array_values(array_unique(array_map(
                static fn (array $replacement): string => $replacement['component_label'],
                $repair->replacements,
            ))),
        ];
    }
}
