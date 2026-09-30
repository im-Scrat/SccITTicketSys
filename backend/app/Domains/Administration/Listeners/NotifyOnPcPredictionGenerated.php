<?php

declare(strict_types=1);

namespace App\Domains\Administration\Listeners;

use App\Domains\Administration\Services\NotificationAudience;
use App\Domains\Administration\Services\NotificationDispatcher;
use App\Domains\KnowledgeBase\Events\PcPredictionGenerated;
use App\Domains\KnowledgeBase\Notifications\PcPredictionGeneratedNotification;
use App\Domains\KnowledgeBase\Services\PcPredictionAccess;

/**
 * A predictive-maintenance finding is ready to review (WP-M; SRS FR-AI-011).
 *
 * Recipients: Administrators — the only role predictions are ever shown to
 * ({@see PcPredictionAccess}), so the
 * notification reaches nobody who could not open its `action_url` anyway.
 * No actor to exclude: the system generated this, nobody performed it.
 */
class NotifyOnPcPredictionGenerated
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationAudience $audience,
    ) {}

    public function handle(PcPredictionGenerated $event): void
    {
        $this->dispatcher->send(
            $this->audience->administrators(),
            new PcPredictionGeneratedNotification($event->prediction),
        );
    }
}
