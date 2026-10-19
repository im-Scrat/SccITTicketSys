<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Listeners;

use App\Domains\KnowledgeBase\Jobs\AnalyzePcHistoryJob;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\Maintenance\Events\MaintenanceCompleted;
use App\Enums\AiEventType;
use App\Models\AiLearningEvent;
use App\Models\PcUnit;
use Illuminate\Support\Facades\DB;

/**
 * WP-L — the first step of the learning loop: a completed repair becomes an
 * `ai_learning_events` row, and its PC is queued for re-assessment
 * (SDD §22.4; SRS FR-AI-012).
 *
 * **Only while predictions are enabled** (`ai_system_settings.enable_predictions`,
 * off by default). Nothing is lost while it is off: the learning event is an
 * index of *when* to look again, not the history itself — the assessment
 * always reads the completed `maintenance_records`, so enabling predictions
 * later still sees every repair made before.
 *
 * **After commit, twice over.** `MaintenanceCompleted` is itself
 * `ShouldDispatchAfterCommit` (WP-K), so this listener only ever runs once the
 * completion is durable; the job dispatch is additionally wrapped in
 * `DB::afterCommit()` — this codebase's convention for listeners that queue
 * work — because queue connections here run with `after_commit => false`.
 *
 * **Once per completion.** The event row is keyed by record and type, and the
 * job is queued only when that row is new, so a repeated delivery neither
 * duplicates the event nor re-runs the analysis.
 *
 * The payload is the record's typed facts only — its uuid, type, completion
 * time and replaced component types — never its diagnosis, notes or anyone's
 * name. The prose stays on the maintenance record, where its access rules are.
 */
class RecordMaintenanceLearningEvent
{
    public function __construct(private readonly AiSettings $settings) {}

    public function handle(MaintenanceCompleted $event): void
    {
        $record = $event->record;

        if (! $this->settings->predictionsEnabled() || $record->pc_unit_id === null) {
            return;
        }

        $record->loadMissing(['type', 'hardwareReplacements.newComponent', 'hardwareReplacements.oldComponent']);

        $componentTypes = $record->hardwareReplacements
            ->map(fn ($replacement): ?string => ($replacement->newComponent ?? $replacement->oldComponent)?->component_type->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $learning = AiLearningEvent::query()->firstOrCreate(
            [
                'maintenance_record_id' => $record->id,
                'event_type' => AiEventType::MaintenanceCompleted->value,
            ],
            [
                'ticket_id' => $record->ticket_id,
                'pc_unit_id' => $record->pc_unit_id,
                'event_summary' => sprintf('%s completed', $record->type->name ?? 'Maintenance'),
                'payload' => [
                    'maintenance_record' => $record->uuid,
                    'type' => $record->type?->slug,
                    'is_preventive' => (bool) $record->type?->is_preventive,
                    'completed_at' => $record->completed_at?->toIso8601String(),
                    'component_types' => $componentTypes,
                ],
            ],
        );

        if (! $learning->wasRecentlyCreated) {
            return;
        }

        $pcUnit = PcUnit::query()->find($record->pc_unit_id);

        if ($pcUnit !== null) {
            DB::afterCommit(fn () => AnalyzePcHistoryJob::dispatch($pcUnit));
        }
    }
}
