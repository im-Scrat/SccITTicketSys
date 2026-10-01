<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Agents\PcFailurePredictionAgent;
use App\Domains\KnowledgeBase\DTOs\DetectedPattern;
use App\Domains\KnowledgeBase\DTOs\PcHistorySnapshot;
use App\Domains\KnowledgeBase\DTOs\PcPredictionResult;
use App\Domains\KnowledgeBase\DTOs\PcRiskAssessment;
use App\Domains\KnowledgeBase\Events\PcPredictionGenerated;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Enums\ActivityAction;
use App\Enums\PredictionStatus;
use App\Models\AiFailurePattern;
use App\Models\AiModel;
use App\Models\AiPrediction;
use App\Models\PcUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One PC's predictive-maintenance assessment, end to end (WP-L; SRS
 * FR-AI-011/012).
 *
 *     completed maintenance history ({@see PcMaintenanceHistory})
 *       → deterministic pattern detection ({@see FailurePatternDetector})
 *       → `ai_failure_patterns` maintained
 *       → no pattern: "Insufficient historical evidence for a reliable prediction."
 *       → pattern: the model interprets it ({@see PcFailurePredictionAgent})
 *       → a validated reading becomes one pending `ai_predictions` row
 *
 * **Advisory, and nothing else.** The only rows this class writes are its own:
 * `ai_failure_patterns`, `ai_predictions`, one activity-log entry, and — only
 * on a genuine new prediction — one {@see PcPredictionGenerated} event that
 * tells administrators there is something to review (WP-M). It never opens a
 * ticket, assigns anyone, records a replacement, retires an asset or changes
 * a PC's status — a prediction is something an administrator reviews, not
 * something the system acts on.
 *
 * **One current finding per PC.** A finished assessment — a new prediction or
 * a considered "insufficient" — supersedes the PC's earlier *pending*
 * predictions (they become `expired`). Confirmed and dismissed ones are an
 * administrator's decisions and are never touched. A run that could not get a
 * usable answer changes no prediction at all: nothing new was learned.
 *
 * The provider call happens outside any transaction, so a slow model never
 * holds a lock; the write that follows re-locks the PC row so two runs for the
 * same machine cannot both leave a pending prediction behind.
 */
class PcRiskAssessor
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly PcMaintenanceHistory $history,
        private readonly FailurePatternDetector $detector,
        private readonly PcHistoryContext $context,
        private readonly SafeAgentInvoker $invoker,
        private readonly AuditLogger $audit,
    ) {}

    public function assess(PcUnit $pcUnit): PcRiskAssessment
    {
        if (! $this->settings->predictionsEnabled()) {
            return PcRiskAssessment::disabled();
        }

        $now = CarbonImmutable::now();
        $snapshot = $this->history->for($pcUnit);
        $patterns = $this->detector->detect($snapshot, $now);
        $stored = $this->maintainPatterns($pcUnit, $patterns, $now);

        if ($patterns === []) {
            $this->supersedePending($pcUnit);
            Log::info('ai.pc_prediction_insufficient', ['pc_unit_id' => $pcUnit->id, 'reason' => 'no_pattern']);

            return PcRiskAssessment::insufficient();
        }

        $primary = $patterns[0];
        $windowDays = $this->detector->windowDays($primary, $now);

        try {
            $model = $this->settings->activeModel();
            $invocation = $this->invoker->invoke(
                new PcFailurePredictionAgent,
                $this->context->buildPrompt($snapshot, $patterns, $windowDays),
                $model,
            );
            $result = PcPredictionResult::fromStructured($invocation->structured ?? []);
        } catch (AiUnavailableException|AiProviderException $e) {
            Log::warning('ai.pc_prediction_skipped', ['pc_unit_id' => $pcUnit->id, 'exception' => $e::class]);

            return PcRiskAssessment::unavailable($patterns);
        }

        if (! $result->sufficientEvidence) {
            $this->supersedePending($pcUnit);
            Log::info('ai.pc_prediction_insufficient', ['pc_unit_id' => $pcUnit->id, 'reason' => 'model_declined']);

            return PcRiskAssessment::insufficient($patterns);
        }

        $prediction = $this->record($pcUnit, $snapshot, $patterns, $stored[$primary->name] ?? null, $windowDays, $result, $model, $now);

        return PcRiskAssessment::predicted($prediction, $patterns);
    }

    /**
     * Keep `ai_failure_patterns` equal to what the history shows now: upsert
     * each detected pattern, remove the PC's patterns that no longer hold (a
     * withdrawn record can dissolve one). `confidence` stays null — these are
     * counts, and a count has no confidence to report.
     *
     * @param  list<DetectedPattern>  $patterns
     * @return array<string, AiFailurePattern> keyed by pattern name
     */
    private function maintainPatterns(PcUnit $pcUnit, array $patterns, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($pcUnit, $patterns, $now): array {
            $stored = [];

            foreach ($patterns as $pattern) {
                $stored[$pattern->name] = AiFailurePattern::query()->updateOrCreate(
                    ['pc_unit_id' => $pcUnit->id, 'pattern_name' => $pattern->name],
                    [
                        'hardware_component_id' => $pattern->hardwareComponentId,
                        'detected_problem' => mb_substr($pattern->detectedProblem, 0, 255),
                        'occurrence_count' => $pattern->occurrenceCount,
                        'average_days_between_failures' => $pattern->averageDaysBetween(),
                        'confidence' => null,
                        'last_detected' => $now,
                    ],
                );
            }

            AiFailurePattern::query()
                ->where('pc_unit_id', $pcUnit->id)
                ->whereNotIn('pattern_name', array_keys($stored))
                ->delete();

            return $stored;
        });
    }

    private function supersedePending(PcUnit $pcUnit): void
    {
        AiPrediction::query()
            ->where('pc_unit_id', $pcUnit->id)
            ->where('status', PredictionStatus::Pending->value)
            ->update(['status' => PredictionStatus::Expired->value]);
    }

    /**
     * @param  list<DetectedPattern>  $patterns
     */
    private function record(
        PcUnit $pcUnit,
        PcHistorySnapshot $snapshot,
        array $patterns,
        ?AiFailurePattern $primaryRow,
        ?int $windowDays,
        PcPredictionResult $result,
        AiModel $model,
        CarbonImmutable $now,
    ): AiPrediction {
        return DB::transaction(function () use ($pcUnit, $snapshot, $patterns, $primaryRow, $windowDays, $result, $model, $now): AiPrediction {
            // Serialize runs for this machine: supersede + insert is one step.
            PcUnit::query()->whereKey($pcUnit->id)->lockForUpdate()->first();

            $this->supersedePending($pcUnit);

            $prediction = AiPrediction::query()->create([
                'pc_unit_id' => $pcUnit->id,
                'ai_model_id' => $model->id,
                'ai_failure_pattern_id' => $primaryRow?->id,
                'predicted_issue' => $result->predictedIssue,
                'risk_level' => $result->riskLevel?->value,
                // No calibrated model exists; a number here would be invented.
                'probability' => null,
                'confidence' => $result->confidence,
                'predicted_within_days' => $windowDays,
                'explanation' => $result->explanation,
                'recommendation' => $result->recommendation,
                'evidence' => $this->evidence($snapshot, $patterns, $windowDays),
                'status' => PredictionStatus::Pending->value,
                'generated_at' => $now,
            ]);

            $this->audit->activity(
                ActivityAction::PcPredictionGenerated,
                actor: null,
                subject: $pcUnit,
                properties: [
                    'ai_model' => $model->model_identifier,
                    'risk_level' => $result->riskLevel?->value,
                    'confidence' => $result->confidence,
                    'pattern' => $patterns[0]->name,
                ],
                module: 'knowledge_base',
                description: "Predictive-maintenance finding generated for {$pcUnit->unit_code}",
            );

            // WP-M: tells the administrators there is something new to review.
            // Dispatched here, inside this same transaction — the listener's
            // own NotificationDispatcher::send() defers delivery to the
            // commit, the same way WorkSupportRequestSubmitted is dispatched
            // inside its action's transaction.
            PcPredictionGenerated::dispatch($prediction);

            return $prediction;
        });
    }

    /**
     * The observed facts and the patterns, exactly as computed — what the
     * model was shown, kept beside what it concluded.
     *
     * @param  list<DetectedPattern>  $patterns
     * @return array<string, mixed>
     */
    private function evidence(PcHistorySnapshot $snapshot, array $patterns, ?int $windowDays): array
    {
        $components = [];
        foreach ($snapshot->correctiveRepairs() as $repair) {
            foreach ($repair->replacements as $replacement) {
                $key = $replacement['component_type'];
                $components[$key] ??= ['component_type' => $key, 'label' => $replacement['component_label'], 'count' => 0];
                $components[$key]['count'] += $replacement['quantity'];
            }
        }

        $corrective = count($snapshot->correctiveRepairs());

        return [
            'observed' => [
                'completed_repairs' => count($snapshot->repairs),
                'corrective_repairs' => $corrective,
                'preventive_visits' => count($snapshot->repairs) - $corrective,
                'first_completed_at' => $snapshot->firstCompletedAt()?->toIso8601String(),
                'last_completed_at' => $snapshot->lastCompletedAt()?->toIso8601String(),
                'components_replaced' => array_values($components),
            ],
            'patterns' => array_map(static fn (DetectedPattern $pattern): array => $pattern->toEvidence(), $patterns),
            'time_window' => [
                'days' => $windowDays,
                'basis' => $windowDays !== null
                    ? 'The strongest pattern\'s average interval, less the days since its last occurrence.'
                    : 'Not stated: the history does not support a time window (too few or too irregular occurrences, or the typical interval has already passed).',
            ],
        ];
    }
}
