<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Services;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\PredictionStatus;
use App\Models\AiPrediction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The one human action a predictive-maintenance finding has (WP-M) —
 * confirming it matched reality, or dismissing it. Both are terminal: from
 * here the finding is closed, one way or the other, by an administrator's own
 * read of it. Neither transition touches equipment, a ticket, an assignment
 * or a replacement — confirming a finding is recording a judgement, not
 * scheduling the preventive action it recommends, which stays a separate,
 * human-initiated step outside this advisory feature entirely.
 *
 * Only a `pending` finding may be decided. A `confirmed`/`dismissed` row is
 * already somebody's decision and does not get a second one; an `expired` row
 * was superseded by a newer assessment before anyone acted on it, and
 * deciding a stale finding would misrepresent what the machine's history
 * currently shows.
 */
class PcPredictionDecision
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function confirm(AiPrediction $prediction, User $actor): AiPrediction
    {
        return $this->decide($prediction, $actor, PredictionStatus::Confirmed, ActivityAction::PcPredictionConfirmed);
    }

    public function dismiss(AiPrediction $prediction, User $actor): AiPrediction
    {
        return $this->decide($prediction, $actor, PredictionStatus::Dismissed, ActivityAction::PcPredictionDismissed);
    }

    private function decide(AiPrediction $prediction, User $actor, PredictionStatus $to, ActivityAction $action): AiPrediction
    {
        if ($prediction->status !== PredictionStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => match ($prediction->status) {
                    PredictionStatus::Expired => 'This finding was superseded by a newer assessment before it was decided.',
                    default => 'This finding was already decided.',
                },
            ])->status(422);
        }

        $prediction->update(['status' => $to->value]);

        $this->audit->activity(
            $action,
            actor: $actor,
            subject: $prediction,
            module: 'knowledge_base',
            description: "Predictive-maintenance finding for {$prediction->pcUnit?->unit_code} {$to->value}",
        );

        return $prediction;
    }
}
