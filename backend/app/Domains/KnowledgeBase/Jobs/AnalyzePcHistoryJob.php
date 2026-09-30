<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Jobs;

use App\Domains\KnowledgeBase\Listeners\RecordMaintenanceLearningEvent;
use App\Domains\KnowledgeBase\Services\PcRiskAssessor;
use App\Models\PcUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WP-L — re-assess one PC's predictive-maintenance risk after a repair on it
 * was completed (SRS FR-AI-011/012). Dispatched by
 * {@see RecordMaintenanceLearningEvent}, after commit.
 *
 * **Unique until processing, per PC.** Several repairs on one machine completed
 * close together need one assessment, not several: while a run is still
 * queued, a further dispatch for the same PC is dropped — the queued run reads
 * the history when it starts, so it already sees the later repair. Once a run
 * has started, a new completion queues a fresh one, so nothing that happened
 * mid-run is missed.
 *
 * **Best-effort, like every AI job here.** {@see PcRiskAssessor} turns every
 * AI failure into an outcome rather than an exception, so a down provider
 * never becomes a retry storm or a queue backlog. A PC deleted before the job
 * runs simply has nothing left to assess.
 */
class AnalyzePcHistoryJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly PcUnit $pcUnit) {}

    public function uniqueId(): string
    {
        return (string) $this->pcUnit->getKey();
    }

    public function handle(PcRiskAssessor $assessor): void
    {
        $assessor->assess($this->pcUnit);
    }
}
