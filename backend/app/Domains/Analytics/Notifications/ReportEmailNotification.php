<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Notifications;

use App\Domains\Administration\Notifications\Concerns\RetriesMailDelivery;
use App\Domains\Administration\Notifications\ProjectNotification;
use App\Models\ReportEmailDelivery;
use App\Models\ReportExport;
use App\Support\SchoolClock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One recipient's copy of an emailed report (Phase 2.10, SDD DD-92).
 *
 * An on-demand mail notification, deliberately not a {@see ProjectNotification}:
 * the recipient may have no account, and nothing here belongs in anyone's
 * in-app notification centre. The body states structural facts only — which
 * report, which period, which format, that the attachment carries identifiers
 * and figures only (FR-NOT-006). It never names the sender to an outside
 * address and never links back into the system for one: an outsider has no
 * account to use it with, and a link is the first thing a forwarded message
 * leaks.
 *
 * Every value reaches the template through Markdown's own escaping
 * (`Markdown::withSecuredEncoding()`, enabled at boot), so a room name written
 * as a Markdown link renders as text.
 */
class ReportEmailNotification extends Notification implements ShouldQueue
{
    // Three attempts, waiting 1, 5 and 15 minutes between them.
    use Queueable, RetriesMailDelivery;

    public function __construct(
        public readonly ReportExport $export,
        public readonly ReportEmailDelivery $delivery,
    ) {
        $this->onQueue(ProjectNotification::NOTIFICATIONS_QUEUE);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $export = $this->export->loadMissing('user');
        $organisation = (string) config('reports.organisation', 'SccIT');
        $period = (string) ($export->filters['period'] ?? 'month');
        $asOf = $export->as_of->setTimezone(SchoolClock::TIMEZONE)->subMinute()->format('j M Y, H:i');

        $message = (new MailMessage)
            ->subject(sprintf('%s report: %s', $organisation, $export->report_type->label()))
            ->greeting('A report has been shared with you')
            ->line($this->delivery->is_external
                ? sprintf('An administrator at %s has sent you a report.', $organisation)
                : sprintf('%s has sent you a report from %s.', $export->user?->fullName() ?? 'An administrator', $organisation))
            ->line(sprintf('Report: %s (%s).', $export->report_type->label(), str_replace('_', ' ', $period)))
            ->line(sprintf('Figures as of %s (%s).', $asOf, SchoolClock::TIMEZONE))
            ->line(sprintf('Format: %s, attached.', $export->format->label()))
            ->line('The attachment contains identifiers and figures only: no ticket titles, descriptions, comments or names.');

        if ($export->path !== null && $export->filename !== null) {
            $message->attachFromStorageDisk($export->disk, $export->path, $export->filename, ['mime' => $export->format->mime()]);
        }

        return $message->salutation($organisation);
    }
}
