<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Console;

use App\Domains\Maintenance\Events\PreventiveMaintenanceDue;
use App\Domains\Maintenance\Services\MaintenanceMetrics;
use App\Domains\Maintenance\Services\MaintenanceVisibility;
use App\Models\MaintenanceRecord;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Detects preventive maintenance that is due or overdue (SRS FR-MNT-007).
 *
 * ── What this does, and what it deliberately does not ──────────────────────
 *
 * FR-MNT-007 asks the system to *generate due reminders* from
 * `maintenance.default_interval_days` and `maintenance.reminder_days`. It does
 * **not** ask it to create maintenance records: FR-MNT-002 is explicit that a
 * preventive record is opened with a `scheduled_for` date by a person. So this
 * command detects and reports; it never writes a `maintenance_records` row, and
 * nothing here invents work nobody scheduled.
 *
 * **Delivery was out of scope for WP-2.6** (Client decision, 2026-08-28), and
 * this command was written with a single place for a later work package to
 * attach dispatch to. **WP-2.7a is that work package**, and the attachment is
 * one line: each detected record raises {@see PreventiveMaintenanceDue}, which
 * the Administration domain turns into a notification for the assigned
 * technician and the administrators (FR-NOT-003 T5).
 *
 * Everything else is unchanged. The command still writes no
 * `maintenance_records` row, and "due" is still exactly the query below — the
 * same predicate behind the `/api/maintenance/scheduled` horizon, the overdue
 * filter and the module dashboard's posture tile, so the sweep, the screens and
 * now the notifications can never disagree about what is overdue.
 *
 * The daily cadence does not become a daily nag: the notification's idempotency
 * key is the record, its overdue flag and the date, so a technician gets one
 * reminder per outstanding visit per day and one distinct message on the day it
 * tips from *due soon* into *overdue*.
 *
 * Daily rather than hourly: the lead time is measured in days, so a finer
 * cadence would re-report the same rows 24 times as often.
 */
class DetectDuePreventiveMaintenance extends Command
{
    protected $signature = 'maintenance:detect-due
                            {--days= : Override the configured reminder lead time}';

    protected $description = 'Report preventive maintenance that is overdue or falls due inside the reminder window';

    public function handle(MaintenanceMetrics $metrics): int
    {
        $cadence = $metrics->cadence();
        $lead = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : $cadence['lead_days'];

        $now = now();
        $threshold = $now->copy()->addDays($lead);

        $overdue = $this->openScheduled()
            ->where('scheduled_for', '<', $now)
            ->orderBy('scheduled_for')
            ->get();

        $dueSoon = $this->openScheduled()
            ->whereBetween('scheduled_for', [$now, $threshold])
            ->orderBy('scheduled_for')
            ->get();

        $this->info(sprintf(
            'Preventive maintenance: %d overdue, %d due within %d day(s). Interval %d day(s).',
            $overdue->count(),
            $dueSoon->count(),
            $lead,
            $cadence['interval_days'],
        ));

        $this->report('Overdue', $overdue);
        $this->report('Due soon', $dueSoon);

        // WP-2.7a — the seam this command was written to leave open.
        foreach ($overdue as $record) {
            PreventiveMaintenanceDue::dispatch($record, true);
        }

        foreach ($dueSoon as $record) {
            PreventiveMaintenanceDue::dispatch($record, false);
        }

        return self::SUCCESS;
    }

    /**
     * Open, dated maintenance — the same predicate `MaintenanceMetrics` and the
     * `/maintenance/scheduled` horizon use, so the command's numbers and the
     * dashboard's are the same numbers.
     *
     * @return Builder<MaintenanceRecord>
     */
    private function openScheduled(): Builder
    {
        return MaintenanceRecord::query()
            ->with(['type:id,name,slug', 'technician:id,uuid,first_name,last_name', 'pcUnit:id,unit_code,pc_name'])
            ->whereIn('status', array_map(
                static fn ($status): string => $status->value,
                MaintenanceVisibility::OPEN_STATUSES,
            ))
            ->whereNotNull('scheduled_for');
    }

    /**
     * @param  Collection<int, MaintenanceRecord>  $records
     */
    private function report(string $heading, $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line($heading.':');

        foreach ($records as $record) {
            $this->line(sprintf(
                '  %s  %s  [%s]  %s',
                $record->scheduled_for?->toDateString() ?? '—',
                str_pad($record->pcUnit->unit_code ?? '—', 12),
                $record->technician?->fullName() ?? 'unassigned',
                $record->title,
            ));
        }
    }
}
