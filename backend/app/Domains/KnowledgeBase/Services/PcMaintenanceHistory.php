<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\KnowledgeBase\DTOs\CompletedRepair;
use App\Domains\KnowledgeBase\DTOs\PcHistorySnapshot;
use App\Enums\MaintenanceStatus;
use App\Models\HardwareReplacement;
use App\Models\MaintenanceNote;
use App\Models\MaintenanceRecord;
use App\Models\PcUnit;
use Carbon\CarbonImmutable;

/**
 * A PC's repair history, read from the one authoritative source (WP-L).
 *
 * **`maintenance_records`, never `activity_logs`.** The activity log records
 * that somebody *did something to a row*; it is an audit trail, not a repair
 * register, and it says nothing reliable about what failed or what was
 * replaced. A completed maintenance record is the repair itself — its type,
 * findings, parts and notes — so it is the only thing history is built from.
 *
 * Completed records only: a scheduled, in-progress, on-hold or cancelled visit
 * is not something that happened to the machine yet (or at all). Soft-deleted
 * records are excluded by the model's own scope — a record an administrator
 * withdrew is not evidence.
 *
 * The linked ticket contributes its category and title only. This pipeline
 * runs as the system, with no viewer, so it takes the least of the ticket that
 * explains the fault and nothing about who reported it.
 */
class PcMaintenanceHistory
{
    /** Notes carried per record — the most recent ones say most about the outcome. */
    private const NOTES_PER_RECORD = 3;

    public function for(PcUnit $pcUnit): PcHistorySnapshot
    {
        $records = MaintenanceRecord::query()
            ->where('pc_unit_id', $pcUnit->getKey())
            ->where('status', MaintenanceStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->with([
                'type',
                'ticket.category',
                'hardwareReplacements.newComponent',
                'hardwareReplacements.oldComponent',
                'notes' => fn ($query) => $query->latest('id'),
            ])
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        return new PcHistorySnapshot(
            pcUnit: $pcUnit,
            repairs: $records->map(fn (MaintenanceRecord $record): CompletedRepair => $this->repair($record))->values()->all(),
        );
    }

    private function repair(MaintenanceRecord $record): CompletedRepair
    {
        $replacements = [];

        foreach ($record->hardwareReplacements as $replacement) {
            /** @var HardwareReplacement $replacement */
            $component = $replacement->newComponent ?? $replacement->oldComponent;

            // A whole-asset swap (no component on either side) says nothing
            // about which part of this machine keeps failing.
            if ($component === null) {
                continue;
            }

            $replacements[] = [
                'component_type' => $component->component_type->value,
                'component_label' => $component->component_type->label(),
                'component_id' => $component->getKey(),
                'component_name' => $component->name,
                'quantity' => (int) $replacement->quantity,
                'reason' => $replacement->replacement_reason,
            ];
        }

        $notes = $record->notes
            ->take(self::NOTES_PER_RECORD)
            ->map(fn (MaintenanceNote $note): string => (string) $note->body)
            ->values()
            ->all();

        return new CompletedRepair(
            id: $record->id,
            uuid: $record->uuid,
            typeName: $record->type->name ?? 'Maintenance',
            isPreventive: (bool) $record->type?->is_preventive,
            completedAt: CarbonImmutable::parse($record->completed_at),
            diagnosis: $record->diagnosis,
            rootCause: $record->root_cause,
            resolution: $record->resolution,
            preventiveRecommendation: $record->preventive_recommendation,
            ticketCategory: $record->ticket?->category?->name,
            ticketTitle: $record->ticket?->title,
            replacements: $replacements,
            notes: $notes,
        );
    }
}
