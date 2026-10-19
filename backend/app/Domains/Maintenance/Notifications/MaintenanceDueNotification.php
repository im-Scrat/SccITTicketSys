<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\MaintenanceRecord;
use App\Models\User;

/**
 * **T5 — a scheduled visit is due or overdue** (SRS FR-MNT-007, FR-NOT-003).
 *
 * Raised by the daily `maintenance:detect-due` sweep, which WP-2.6 shipped as
 * detection-only precisely because there was no channel to deliver on. This is
 * the delivery half, attached at the seam that command left for it.
 *
 * ── Once a day per record, and once more when it tips over ─────────────────
 *
 * The sweep re-derives the same overdue set every morning. The dedupe key is
 * therefore the record, the **overdue flag** and the **date** — which produces
 * exactly the cadence a person would want: a daily reminder while something is
 * outstanding, and a distinct message on the day it stops being *due soon* and
 * becomes *overdue*. Dropping the date would announce an overdue visit once and
 * then fall silent; dropping the flag would let the day it tips over pass
 * unremarked.
 */
class MaintenanceDueNotification extends ProjectNotification
{
    public function __construct(
        private readonly MaintenanceRecord $record,
        private readonly bool $overdue,
    ) {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::MaintenanceDue;
    }

    public function dedupeKey(): ?string
    {
        return implode(':', [
            $this->topic()->value,
            $this->record->uuid,
            $this->overdue ? 'overdue' : 'due',
            now()->toDateString(),
        ]);
    }

    public function payload(User $notifiable): array
    {
        $this->record->loadMissing(['pcUnit.room', 'type']);

        $code = $this->record->pcUnit?->unit_code;
        $unit = $code ?? 'unassigned equipment';

        return [
            'title' => $this->overdue
                ? "Maintenance overdue: {$unit}"
                : "Maintenance due soon: {$unit}",
            'message' => $this->record->title,
            'data' => array_filter([
                'maintenance' => $this->record->uuid,
                'pc_unit' => $this->record->pcUnit?->unit_code,
                'room' => $this->record->pcUnit?->room?->name,
                'type' => $this->record->type?->slug,
                'scheduled_for' => $this->record->scheduled_for?->toIso8601String(),
                'overdue' => $this->overdue,
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
        $lines = [$payload['title'].'.'];

        if (isset($payload['data']['scheduled_for'])) {
            $lines[] = 'It was scheduled for '.$payload['data']['scheduled_for'].'.';
        }

        return $lines;
    }
}
