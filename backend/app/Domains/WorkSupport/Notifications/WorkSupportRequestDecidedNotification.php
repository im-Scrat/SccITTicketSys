<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Enums\WorkSupportStatus;
use App\Models\User;
use App\Models\WorkSupportRequest;

/**
 * **T10 — your request has been answered** (SRS FR-WSR-006/007/008,
 * FR-WSR-012, FR-NOT-003).
 *
 * Goes to the **submitting technician**, named verbatim by FR-NOT-003. The other
 * half of the WP-2.6b carry-forward, and the notification that closes the loop
 * the workflow opened: a technician who raised a request from a machine and
 * walked away had, until now, no way to learn the answer except by going back
 * to look.
 *
 * ── One notification for three decisions ───────────────────────────────────
 *
 * Approve-and-reschedule, request-a-discussion and decline are one event to the
 * recipient — *the administrator has answered* — and differ only in wording. The
 * lifecycle already keeps them distinct where it matters, in the transition map
 * and the audit trail.
 *
 * ── What is carried and what is not ────────────────────────────────────────
 *
 * The matrix permits the new schedule, the clarification reason and the decline
 * reason here, on the grounds that all three were authored *for* this
 * technician. The split taken is by kind rather than by permission: the
 * **new date** is a structural fact and travels, in the payload and in the mail,
 * because it is the one thing the technician has to act on. The **written
 * reasons** stay on the request. They are the part of an administrator's answer
 * that deserves to be read in context beside the request it answers, and keeping
 * prose out of mail bodies is the rule that makes FR-NOT-006's "safe for
 * external delivery" checkable rather than a judgement call per message.
 */
class WorkSupportRequestDecidedNotification extends ProjectNotification
{
    public function __construct(
        private readonly WorkSupportRequest $request,
        private readonly WorkSupportStatus $decision,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::WorkSupportDecided;
    }

    /**
     * Keyed on the request **and the decision**.
     *
     * The transition map lets `clarification_requested` be followed by an
     * `approved` or `declined` answer later, so one request can legitimately
     * produce two decisions. Keying on the request alone would silently swallow
     * the second — the one that actually settles it.
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->request->uuid.':'.$this->decision->value;
    }

    public function payload(User $notifiable): array
    {
        $this->request->loadMissing('pcUnit');

        $code = $this->request->pcUnit?->unit_code;
        $unit = $code ?? 'your request';

        return [
            'title' => match ($this->decision) {
                WorkSupportStatus::Approved => "Work support approved for {$unit}",
                WorkSupportStatus::ClarificationRequested => "Discussion requested about {$unit}",
                WorkSupportStatus::Declined => "Work support declined for {$unit}",
                default => "Work support request updated for {$unit}",
            },
            'message' => match ($this->decision) {
                WorkSupportStatus::Approved => 'The work has been rescheduled. Open the request to acknowledge the new date.',
                WorkSupportStatus::ClarificationRequested => 'An administrator wants to discuss this face to face. Open the request for the details.',
                WorkSupportStatus::Declined => 'Open the request to read the reason it was declined.',
                default => 'Open the request to see what changed.',
            },
            'data' => array_filter([
                'work_support_request' => $this->request->uuid,
                'pc_unit' => $this->request->pcUnit?->unit_code,
                'decision' => $this->decision->value,
                'rescheduled_to' => $this->request->rescheduled_to?->toIso8601String(),
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => '/app/work-support',
        ];
    }

    /**
     * @param  array{title: string, message: string|null, data: array<string, mixed>, action_url: string|null}  $payload
     * @return list<string>
     */
    protected function mailLines(array $payload): array
    {
        $lines = [$payload['title'].'.'];

        if (isset($payload['data']['rescheduled_to'])) {
            $lines[] = 'The work is now scheduled for '.$payload['data']['rescheduled_to'].'.';
        }

        return $lines;
    }
}
