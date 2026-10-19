<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Events;

use App\Domains\KnowledgeBase\Services\PcRiskAssessor;
use App\Models\AiPrediction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when {@see PcRiskAssessor}
 * writes a new predictive-maintenance finding (WP-L/WP-M; SRS FR-AI-011).
 *
 * Dispatched inside `PcRiskAssessor`'s own transaction, the same way
 * `WorkSupportRequestSubmitted` is dispatched inside its action's transaction
 * — the listener's own `NotificationDispatcher::send()` defers delivery to
 * the commit itself, so this event needs no `ShouldDispatchAfterCommit` of
 * its own.
 *
 * Not raised for "insufficient evidence" or "unavailable": those are not new
 * information for an administrator to act on, and a notification for every
 * background re-assessment that found nothing would be noise, not a signal.
 */
class PcPredictionGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AiPrediction $prediction) {}
}
