<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Jobs;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Agents\TicketPreScreeningAgent;
use App\Domains\KnowledgeBase\DTOs\TicketAnalysisResult;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Listeners\AnalyzeTicketOnCreated;
use App\Domains\KnowledgeBase\Services\AiSettings;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Domains\KnowledgeBase\Services\TicketAnalysisContext;
use App\Enums\ActivityAction;
use App\Models\AiAnalysisLog;
use App\Models\AiRecommendation;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * AI analysis at all — this is advisory. On any failure
 * ({@see AiUnavailableException} — not configured; {@see AiProviderException}
 * — provider/timeout/rate-limit/malformed output, all already retried where
 * retrying could help by {@see SafeAgentInvoker}) this job logs and returns
 * normally rather than throwing, so Laravel's queue does not retry-storm an
 * advisory feature and a down provider never becomes a queue backlog.
 */
class AnalyzeTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Ticket $ticket) {}

    public function handle(
        TicketAnalysisContext $context,
        SafeAgentInvoker $invoker,
        AiSettings $settings,
        AuditLogger $audit,
    ): void {
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
        } catch (AiUnavailableException|AiProviderException $e) {
            Log::warning('ai.ticket_analysis_skipped', [
                'ticket_id' => $this->ticket->id,
                'exception' => $e::class,
            ]);

            return;
        }

        DB::transaction(function () use ($model, $invocation, $result, $audit): void {
            $log = AiAnalysisLog::query()->create([
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

            $audit->activity(
                ActivityAction::TicketAiAnalyzed,
                actor: null,
                subject: $this->ticket,
                properties: [
                    'ai_model' => $model->model_identifier,
                    'category' => $result->problemCategory,
                    'severity' => $result->severity->value,
                    'confidence' => $result->confidence,
                ],
                module: 'knowledge_base',
                description: "AI pre-screening completed for ticket {$this->ticket->ticket_number}",
            );
        });
    }
}
