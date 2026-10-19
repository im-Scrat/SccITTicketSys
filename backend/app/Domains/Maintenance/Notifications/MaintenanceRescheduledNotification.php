<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\MaintenanceRecord;
use App\Models\User;

/**
 * **T6 — the date for your visit has moved** (SRS FR-MNT-003, FR-WSR-006,
 * FR-NOT-003).
 *
 * The one notification in this set where a date change *is* the content, so both
 * ends of the move are carried: a technician told only "rescheduled" has to open
 * the record to learn whether it came forward or went back.
 *
 * ── The administrator's reason is not repeated ─────────────────────────────
 *
 * `reschedule_reason` is free text an administrator wrote, and the matrix
 * limits this trigger to the recorded note rather than "the administrator's
 * internal reasoning". It is on the record where the technician can read it in
 * context; copying it into a payload that is also mirrored to email would put an
 * administrator's words about a technician's work into an inbox, which is a
 * different act from writing them on the record.
 *
 * Both write paths raise the same event — the direct edit in
 * `UpdateMaintenanceRecord` and the work-support approval in
 * `WorkSupportRequestLifecycle::approve()` — so a technician is told about a
 * reschedule whichever door it came through.
 */
class MaintenanceRescheduledNotification extends ProjectNotification
{
    public function __construct(
        private readonly MaintenanceRecord $record,
        private readonly ?string $from,
        private readonly ?string $to,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::MaintenanceRescheduled;
    }

    /**
     * Keyed on the record **and the destination date**.
     *
     * A visit can legitimately be rescheduled more than once, and each move is
     * news; two attempts at the *same* move are not. Including the target date
     * separates those cases without needing a history row to point at.
     */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->record->uuid.':'.($this->to ?? 'cleared');
    }

    public function payload(User $notifiable): array
    {
        $this->record->loadMissing(['pcUnit.room', 'type']);

        $unit = $this->record->pcUnit?->unit_code;

        return [
            'title' => 'Maintenance rescheduled: '.($unit ?? 'unassigned equipment'),
            'message' => $this->record->title,
            'data' => array_filter([
                'maintenance' => $this->record->uuid,
                'pc_unit' => $this->record->pcUnit?->unit_code,
                'room' => $this->record->pcUnit?->room?->name,
                'from' => $this->from,
                'to' => $this->to,
            ], static fn (mixed $value): bool => $value !== null),
            'action_url' => "/app/maintenance/{$this->record->uuid}",
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
            isset($payload['data']['to'])
                ? 'Now scheduled for '.$payload['data']['to'].'.'
                : 'It no longer has a scheduled date.',
        ];
    }
}
