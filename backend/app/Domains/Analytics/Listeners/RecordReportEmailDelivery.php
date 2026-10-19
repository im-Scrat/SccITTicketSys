<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Notifications\ReportEmailNotification;
use App\Enums\ReportEmailDeliveryStatus;
use App\Support\Mail\MailFailure;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Records what happened to each emailed report copy (Phase 2.10, SDD DD-92):
 * `sent` when the mail transport accepted it, `failed` when it threw. A copy
 * still `queued` a day later is marked failed by the prune command.
 *
 * `sent` means the relay answered 250 — accepted for delivery. Whether the
 * message then reached an inbox, bounced or was filed as spam happens after
 * that, at the recipient's server, and is visible only in the provider's
 * activity log (SMTP2GO Activity); the application never claims it.
 *
 * A failure records *why* ({@see MailFailure}): `smtp_auth_failed` (535 — our
 * credentials, not the recipient), `smtp_rejected` (other 5xx — permanent),
 * `smtp_deferred` (4xx — rate limit or busy relay; the job retries with
 * backoff), `tls_unavailable`, `connection_failed`, or `transport_error`.
 *
 * Only that name is stored — never the server's text, which can quote
 * addresses or the relay account name. Each attempt overwrites the last, so a
 * deferral that succeeds on retry ends as `sent`.
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
            'failure_code' => $sent ? null : MailFailure::classify($event->data['exception'] ?? null),
            'updated_at' => now(),
        ]);
    }
}
