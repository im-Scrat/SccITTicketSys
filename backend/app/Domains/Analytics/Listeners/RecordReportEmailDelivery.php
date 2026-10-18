<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Notifications\ReportEmailNotification;
use App\Enums\ReportEmailDeliveryStatus;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Records what happened to each emailed report copy (Phase 2.10, SDD DD-92):
 * `sent` when the mail transport accepted it, `failed` when it threw. A copy
 * still `queued` a day later is marked failed by the prune command.
 */
class RecordReportEmailDelivery
{
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        if (! $event->notification instanceof ReportEmailNotification) {
            return;
        }

        $delivery = $event->notification->delivery;
        $sent = $event instanceof NotificationSent;

        $delivery->newQuery()->whereKey($delivery->getKey())->update([
            'status' => $sent ? ReportEmailDeliveryStatus::Sent->value : ReportEmailDeliveryStatus::Failed->value,
            'sent_at' => $sent ? now() : null,
            'failed_at' => $sent ? null : now(),
            'failure_code' => $sent ? null : 'transport_error',
            'updated_at' => now(),
        ]);
    }
}
