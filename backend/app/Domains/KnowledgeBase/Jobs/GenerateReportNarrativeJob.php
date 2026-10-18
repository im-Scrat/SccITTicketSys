<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Jobs;

use App\Domains\Analytics\DTOs\ReportEvidencePacket;
use App\Domains\Identity\Services\AuditLogger;
use App\Domains\KnowledgeBase\Agents\ReportNarrativeAgent;
use App\Domains\KnowledgeBase\DTOs\ReportNarrativeResult;
use App\Domains\KnowledgeBase\Exceptions\AiProviderException;
use App\Domains\KnowledgeBase\Exceptions\AiUnavailableException;
use App\Domains\KnowledgeBase\Services\ReportNarrativeContext;
use App\Domains\KnowledgeBase\Services\SafeAgentInvoker;
use App\Enums\ActivityAction;
use App\Enums\ReportNarrativeStatus;
use App\Models\ReportNarrative;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Generates one AI interpretation of the overall report (Phase 2.10 R5,
 * SDD DD-95), on the `ai` queue like every other AI job.
 *
 * It interprets the facts **stored on the row** — the ones the cache key was
 * computed from — never a fresh read, so what is shown beside the reading is
 * exactly what the model saw. A transient provider failure releases the job
 * with a growing delay until `retryUntil` (the queue's `tries` is 1, so the
 * window, not a count, bounds it); a malformed answer or a refusal fails the
 * row. The audit row records the outcome and counts, never any text.
 */
class GenerateReportNarrativeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'ai';

    public int $timeout = 150;

    public int $maxExceptions = 2;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $narrativeId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return 'report-narrative:'.$this->narrativeId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(SafeAgentInvoker $invoker, ReportNarrativeContext $context, AuditLogger $audit): void
    {
        $narrative = ReportNarrative::query()->find($this->narrativeId);

        if ($narrative === null || $narrative->status !== ReportNarrativeStatus::Pending) {
            return;
        }

        $packet = ReportEvidencePacket::fromArray($narrative->evidence);

        try {
            $invocation = $invoker->invoke(new ReportNarrativeAgent, $context->build($packet));
            $result = ReportNarrativeResult::fromStructured($invocation->structured ?? [], $packet);
        } catch (AiProviderException $e) {
            if ($e->isTransient()) {
                $this->release([20, 60, 180][$this->attempts() - 1] ?? 300);

                return;
            }
            $this->finish($narrative, ReportNarrativeStatus::Failed, 'provider_refused', $audit);

            return;
        } catch (AiUnavailableException) {
            $this->finish($narrative, ReportNarrativeStatus::Unavailable, 'not_configured', $audit);

            return;
        } catch (InvalidArgumentException) {
            $this->finish($narrative, ReportNarrativeStatus::Failed, 'malformed_output', $audit);

            return;
        }

        $narrative->forceFill([
            'status' => ReportNarrativeStatus::Completed,
            'model' => $invocation->model,
            'coverage' => $result->coverage,
            'findings' => $result->findings,
            'limitations' => $result->limitations,
            'withheld_count' => $result->withheld,
            'prompt_tokens' => $invocation->promptTokens,
            'completion_tokens' => $invocation->completionTokens,
            'latency_ms' => $invocation->latencyMs,
            'generated_at' => now(),
            'error_code' => null,
        ])->save();

        $audit->activity(
            ActivityAction::ReportNarrativeGenerated,
            $narrative->requester,
            $narrative,
            ['status' => 'completed', 'findings' => count($result->findings), 'withheld' => $result->withheld, 'withheld_reasons' => $result->withheldReasons, 'model' => $invocation->model],
            Request::create('/', 'POST'),
            'ai',
        );
    }

    public function failed(?Throwable $exception): void
    {
        $narrative = ReportNarrative::query()->find($this->narrativeId);

        if ($narrative !== null && $narrative->status === ReportNarrativeStatus::Pending) {
            $narrative->forceFill(['status' => ReportNarrativeStatus::Failed, 'error_code' => 'gave_up'])->save();
        }

        Log::warning('ai.report_narrative_failed', ['narrative_id' => $this->narrativeId, 'exception' => $exception !== null ? $exception::class : null]);
    }

    private function finish(ReportNarrative $narrative, ReportNarrativeStatus $status, string $code, AuditLogger $audit): void
    {
        $narrative->forceFill(['status' => $status, 'error_code' => $code])->save();

        $audit->activity(
            ActivityAction::ReportNarrativeGenerated,
            $narrative->requester,
            $narrative,
            ['status' => $status->value, 'error' => $code],
            Request::create('/', 'POST'),
            'ai',
        );
    }
}
