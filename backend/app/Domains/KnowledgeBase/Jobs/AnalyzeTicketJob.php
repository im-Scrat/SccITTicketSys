<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Jobs;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\DTOs\AiInvocationResult;
use App\Domains\KnowledgeBase\DTOs\TicketAnalysisResult;
use App\Domains\KnowledgeBase\Events\AiAnalysisReady;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Listeners\AnalyzeTicketOnCreated;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\GeminiGovernor;
use App\Domains\KnowledgeBase\Services\PriorityRecommendationPolicy;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Domains\KnowledgeBase\Services\TicketAnalysisContext;
use App\Enums\ActivityAction;
use App\Models\AiAnalysisLog;
use App\Models\AiModel;
use App\Models\AiRecommendation;
use App\Models\Ticket;
use App\Models\TicketPriority;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WP-I — AI pre-screening for one newly-reported ticket (SRS FR-AI-021).
 *
 * Dispatched by {@see AnalyzeTicketOnCreated}
 * only after the creating transaction has committed (`DB::afterCommit()` in
 * that listener) — by the time this job's `handle()` runs, `SerializesModels`
 * has already re-fetched `$ticket` fresh from the database by its key, so
 * this job can never observe an uncommitted or since-changed row.
 *
 * **Best-effort, not required.** A ticket exists and is fully usable with no
 * AI analysis at all — this is advisory. A failure that will not change on
 * retry ({@see AiUnavailableException} — not configured; a malformed answer)
 * is logged and the job ends normally, so the queue never retry-storms an
 * advisory feature.
 *
 * ── Phase 2.9: many tickets at once ──────────────────────────────────────
 *
 * Each ticket is its own job on the dedicated `ai` queue, worked by its own
 * pool of workers (`queue_ai_prod`), so Teacher A's ticket never waits behind
 * Teacher B's — up to the {@see GeminiGovernor}'s concurrency cap they are
 * analysed in parallel, and beyond it they wait for a slot rather than fail.
 *
 *  - **Transient** provider trouble (busy governor, 429, timeout, 5xx) now
 *    *releases* the job with a growing delay instead of dropping the analysis.
 *    Releases do not count against {@see $maxExceptions}; {@see retryUntil()}
 *    bounds the whole effort to half an hour.
 *  - **Idempotent.** The row carries `analysis_key = ticket:{id}:created`,
 *    unique in the database. A re-run (a retry after a worker died, a duplicate
 *    dispatch) checks it first, re-checks it under a lock on the ticket row,
 *    and treats a unique violation as "already done" — one ticket can never
 *    end up with two on-creation analyses.
 *  - **Unique until processing**, per ticket, so a burst of dispatches for the
 *    same ticket queues one job.
 *  - `$timeout` covers the invoker's worst case (three attempts at the agent
 *    timeout, plus the governor's wait); `retry_after` on the queue connection
 *    is set above it so a slow analysis is never handed to a second worker.
 */
class AnalyzeTicketJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'ai';

    /** How long a ticket's analysis keeps retrying; the AI panel's "pending" window. */
    public const RETRY_WINDOW_MINUTES = 30;

    /** Genuine failures (not releases) before the job is marked failed. */
    public int $maxExceptions = 3;

    /** Seconds; must stay below the queue connection's `retry_after`. */
    public int $timeout = 150;

    /** A ticket deleted before its analysis runs has nothing left to analyse. */
    public bool $deleteWhenMissingModels = true;

    /** Seconds a duplicate dispatch for the same ticket is suppressed. */
    public int $uniqueFor = 900;

    public function __construct(public readonly Ticket $ticket)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->ticket->getKey();
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    /** The idempotency key of the on-creation pre-screening (see the migration). */
    public static function analysisKeyFor(Ticket $ticket): string
    {
        return "ticket:{$ticket->getKey()}:created";
    }

    public function handle(
        TicketAnalysisContext $context,
        SafeAgentInvoker $invoker,
        AiSettings $settings,
        AuditLogger $audit,
        PriorityRecommendationPolicy $priorityPolicy,
    ): void {
        $key = self::analysisKeyFor($this->ticket);

        // Cheap fast path for a re-run; the authoritative check is the unique
        // index, inside the transaction below.
        if (AiAnalysisLog::query()->where('analysis_key', $key)->exists()) {
            return;
        }

        try {
            $model = $settings->activeModel();
            $prompt = $context->buildPrompt($this->ticket);
            // The reporter's name is known to be in the text (teachers sign
            // their reports); the invoker strips it, and every email/phone,
            // before anything reaches the provider (FR-AI-030).
            $this->ticket->loadMissing('reporter');
            $names = array_filter([$this->ticket->reporter?->fullName()]);
            $invocation = $invoker->invoke(new TicketPreScreeningAgent, $prompt, $model, $names);
            $result = TicketAnalysisResult::fromStructured($invocation->structured ?? []);
        } catch (AiProviderException $e) {
            if ($e->isTransient()) {
                $delay = $this->retryDelay();

                Log::info('ai.ticket_analysis_deferred', [
                    'ticket_id' => $this->ticket->id,
                    'attempt' => $this->attempts(),
                    'retry_in_seconds' => $delay,
                ]);

                $this->release($delay);

                return;
            }

            $this->skip($e);

            return;
        } catch (AiUnavailableException $e) {
            $this->skip($e);

            return;
        }

        try {
            $this->persist($key, $model, $invocation, $result, $audit, $priorityPolicy);
        } catch (UniqueConstraintViolationException) {
            // Another worker finished this ticket first. Its row stands; this
            // run's answer is discarded rather than written twice.
            Log::info('ai.ticket_analysis_duplicate_discarded', ['ticket_id' => $this->ticket->id]);
        }
    }

    /**
     * Called once the job will not run again (retryUntil passed, or too many
     * genuine exceptions). The ticket stays fully usable without an analysis;
     * this records only that one was not produced — never ticket content.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('ai.ticket_analysis_failed', [
            'ticket_id' => $this->ticket->getKey(),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    /** Back off further each time: 15 s, 1 min, 3 min, then every 5 min. */
    private function retryDelay(): int
    {
        return [15, 60, 180][$this->attempts() - 1] ?? 300;
    }

    private function skip(AiProviderException|AiUnavailableException $e): void
    {
        Log::warning('ai.ticket_analysis_skipped', [
            'ticket_id' => $this->ticket->id,
            'exception' => $e::class,
        ]);
    }

    private function persist(
        string $key,
        AiModel $model,
        AiInvocationResult $invocation,
        TicketAnalysisResult $result,
        AuditLogger $audit,
        PriorityRecommendationPolicy $priorityPolicy,
    ): void {
        DB::transaction(function () use ($key, $model, $invocation, $result, $audit, $priorityPolicy): void {
            // Serialise writers for this one ticket, then re-check: of two
            // workers that both reached here, the second sees the first's row.
            // The locked row is also what the priority rule reads, so a human
            // priority change committed meanwhile is seen, not overwritten.
            $locked = Ticket::query()->whereKey($this->ticket->getKey())->lockForUpdate()->first();

            if (AiAnalysisLog::query()->where('analysis_key', $key)->exists()) {
                return;
            }

            $recommended = $result->recommendedPriority !== null
                ? TicketPriority::query()->where('slug', $result->recommendedPriority)->where('is_active', true)->first()
                : null;

            $log = AiAnalysisLog::query()->create([
                'analysis_key' => $key,
                'ticket_id' => $this->ticket->id,
                'ai_model_id' => $model->id,
                'analyzed_at' => now(),
                'confidence_score' => $result->confidence,
                'problem_category' => $result->problemCategory,
                'severity' => $result->severity->value,
                'estimated_resolution_minutes' => $result->estimatedResolutionMinutes,
                'technician_required' => $result->technicianRequired,
                'summary' => $result->summary,
                'raw_response' => $invocation->structured,
                'prompt_tokens' => $invocation->promptTokens,
                'completion_tokens' => $invocation->completionTokens,
                'latency_ms' => $invocation->latencyMs,
                'recommended_priority_id' => $recommended?->getKey(),
            ]);

            foreach ($result->recommendations as $index => $recommendation) {
                AiRecommendation::query()->create([
                    'ai_analysis_log_id' => $log->id,
                    'step_order' => $index + 1,
                    'recommendation' => $recommendation,
                ]);
            }

            // The denormalized "ticket AI snapshot" — pre-existing columns on
            // `tickets` (ai_summary / ai_confidence / estimated_resolution_minutes
            // / technician_required) that TicketDetailResource has been reading,
            // administrator-only, since before this job existed to write them.
            $this->ticket->forceFill([
                'ai_summary' => $result->summary,
                'ai_confidence' => $result->confidence,
                'estimated_resolution_minutes' => $result->estimatedResolutionMinutes,
                'technician_required' => $result->technicianRequired,
            ])->save();

            // Phase 2.9 (D-B): the model recommended; deterministic code decides.
            if ($locked !== null) {
                $priorityPolicy->applyIfAllowed($locked, $recommended, $result->confidence, $result->priorityRationale);
            }

            $audit->activity(
                ActivityAction::TicketAiAnalyzed,
                actor: null,
                subject: $this->ticket,
                properties: [
                    'ai_model' => $model->model_identifier,
                    'category' => $result->problemCategory,
                    'severity' => $result->severity->value,
                    'confidence' => $result->confidence,
                    'recommended_priority' => $recommended?->slug,
                ],
                module: 'knowledge_base',
                description: "AI pre-screening completed for ticket {$this->ticket->ticket_number}",
            );

            // Phase 2.9 — tell the reporter's open page, and only the
            // reporter, that the panel can refresh. Held until commit.
            $reporter = $this->ticket->reporter;

            if ($reporter !== null) {
                AiAnalysisReady::dispatch($reporter->uuid, $this->ticket->uuid);
            }
        });
    }
}
