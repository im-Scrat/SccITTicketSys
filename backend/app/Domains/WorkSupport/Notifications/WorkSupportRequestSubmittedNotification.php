<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\User;
use App\Models\WorkSupportRequest;

/**
 * **T9 — a technician is asking for help finishing a job** (SRS FR-WSR-001,
 * FR-WSR-012, FR-NOT-003).
 *
 * Goes to **Administrators**, which FR-NOT-003 names verbatim rather than
 * leaving to inference. This is one half of the debt WP-2.6b recorded when it
 * shipped the workflow: the request reached the administrator inbox, and nothing
 * told anybody it was there.
 *
 * Administrators already see the whole estate, so there is no field on the
 * request they may not read — the matrix says as much. The explanation is
 * nonetheless left on the record rather than copied into the payload, for the
 * reason that governs every notification in this work package: the message
 * carries structural facts, and free text a person typed stays where the
 * existing authorization can decide who reads it. It also keeps a technician's
 * account of a broken machine out of an email that will sit in an inbox.
 */
class WorkSupportRequestSubmittedNotification extends ProjectNotification
{
    public function __construct(
        private readonly WorkSupportRequest $request,
        private readonly int $itemCount,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::WorkSupportSubmitted;
    }

    /**
     * Keyed on the request.
     *
     * Per recipient, so every administrator gets their own row from the one
     * submission — the unique index is `(user_id, dedupe_key)` precisely so a
     * role audience does not collapse into a single notification for whoever the
     * queue happened to reach first.
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->request->uuid;
    }

    public function payload(User $notifiable): array
    {
        $this->request->loadMissing(['pcUnit', 'technician']);

        $code = $this->request->pcUnit?->unit_code;
        $unit = $code ?? 'unknown equipment';

        return [
            'title' => "Work support requested for {$unit}",
            'message' => $this->request->technician?->fullName(),
            'data' => array_filter([
                'work_support_request' => $this->request->uuid,
                'pc_unit' => $this->request->pcUnit?->unit_code,
                'technician' => $this->request->technician?->uuid,
                'items' => $this->itemCount,
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => '/app/work-support/manage',
        ];
    }

    /**
     * @param  array{title: string, message: string|null, data: array<string, mixed>, action_url: string|null}  $payload
     * @return list<string>
     */
    protected function mailLines(array $payload): array
    {
        return [
            $payload['title'].'.',
            sprintf('%d item(s) requested.', (int) ($payload['data']['items'] ?? 0)),
        ];
    }
}
