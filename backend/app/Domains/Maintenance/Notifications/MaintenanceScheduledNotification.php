<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Notifications;

use App\Domains\Administration\Notifications\ProjectNotification;
use App\Enums\NotificationTopic;
use App\Models\MaintenanceRecord;
use App\Models\User;

/**
 * **T5 — a maintenance visit has been put on you** (SRS FR-MNT-002,
 * FR-NOT-003).
 *
 * Goes to the technician the record names, and only when somebody *else* opened
 * it: a technician who raises their own corrective job does not need to be told
 * about it, and the dispatcher drops the actor from every recipient list for
 * exactly that reason.
 *
 * ── The FR-QR-012 field list is the ceiling here too ───────────────────────
 *
 * The matrix is explicit that maintenance notifications must not carry anything
 * outside the approved operational field list — **never price, supplier,
 * warranty or procurement data**. `maintenance_records` has `cost` and
 * `labor_hours` columns sitting right beside the fields below, so this is a live
 * hazard rather than a theoretical one: what is included is the machine, the
 * room, the kind of work and the date, and the commercial columns are absent by
 * decision, not by accident.
 */
class MaintenanceScheduledNotification extends ProjectNotification
{
    public function __construct(private readonly MaintenanceRecord $record)
    {
        parent::__construct();
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::MaintenanceScheduled;
    }

    /** One record, one "you have been given this" — however often it retries. */
    public function dedupeKey(): ?string
    {
        return $this->topic()->value.':'.$this->record->uuid;
    }

    public function payload(User $notifiable): array
    {
        $this->record->loadMissing(['pcUnit.room', 'type']);

        $unit = $this->record->pcUnit?->unit_code;

        return [
            'title' => 'Maintenance assigned: '.($unit ?? 'unassigned equipment'),
            'message' => $this->record->title,
            'data' => array_filter([
                'maintenance' => $this->record->uuid,
                'pc_unit' => $this->record->pcUnit?->unit_code,
                'room' => $this->record->pcUnit?->room?->name,
                'type' => $this->record->type?->slug,
                'scheduled_for' => $this->record->scheduled_for?->toIso8601String(),
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
            $lines[] = 'Scheduled for '.$payload['data']['scheduled_for'].'.';
        }

        return $lines;
    }
}
