<?php

declare(strict_types=1);

namespace App\Domains\KnowledgeBase\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Domains\KnowledgeBase\Http\Controllers\Admin\AiPredictionController;
use App\Enums\NotificationTopic;
use App\Models\AiPrediction;
use App\Models\User;

/**
 * A new predictive-maintenance finding is ready for an administrator to
 * review (WP-M; SRS FR-AI-011).
 *
 * The message states the risk level and the predicted issue — the triage
 * facts — and nothing of the model's explanation or recommendation prose,
 * matching this codebase's PII/content-minimization posture for AI output in
 * a notification. The full reading is one click away, behind authentication,
 * on the review surface {@see AiPredictionController}
 * serves.
 */
class PcPredictionGeneratedNotification extends ProjectNotification
{
    public function __construct(private readonly AiPrediction $prediction)
    {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::PcPredictionGenerated;
    }

    /**
     * One notification per finding, not per dispatch attempt — a queue retry
     * must not duplicate the row, but a genuinely later prediction for the
     * same PC (its own new uuid) is real news and gets its own.
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->prediction->uuid;
    }

    public function payload(User $notifiable): array
    {
        $this->prediction->loadMissing('pcUnit');

        // `ai_predictions.pc_unit_id` is a required, cascade-deleted FK — the
        // relation cannot be null.
        $unit = $this->prediction->pcUnit->unit_code;

        return [
            'title' => "Predictive maintenance: {$unit}",
            'message' => sprintf(
                '%s risk — %s',
                $this->prediction->risk_level?->label() ?? 'Unrated',
                $this->prediction->predicted_issue,
            ),
            'data' => array_filter([
                'prediction' => $this->prediction->uuid,
                'pc_unit' => $this->prediction->pcUnit?->unit_code,
                'risk_level' => $this->prediction->risk_level?->value,
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => "/app/predictions/{$this->prediction->uuid}",
        ];
    }

    /**
     * @param  array{title: string, message: string|null, data: array<string, mixed>, action_url: string|null}  $payload
     * @return list<string>
     */
    protected function mailLines(array $payload): array
    {
        return [$payload['title'].'.', (string) $payload['message'].'.'];
    }
}
