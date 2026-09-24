<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Services;

use App\Domains\Analytics\Services\AssetMetrics;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cross-estate maintenance rollups for the module dashboard
 * (SRS FR-MNT-007, FR-DSH-003).
 *
 * Distinct from {@see AssetMetrics::maintenancePosture()}, which answers the
 * same question for the *role dashboard* with a hard-coded lead time and a
 * per-technician filter. This one reads the configured interval and lead time
 * from `system_settings` — the values FR-MNT-007 names — and adds the
 * distributions a module dashboard needs.
 *
 * Every figure is derived at read time from `maintenance_records`. Nothing here
 * is stored, cached or denormalized (DR-019's stance for dashboards): "overdue"
 * is a statement about now, and a persisted flag would be wrong the moment the
 * clock passed it with nobody writing a row.
 *
 * **This class is only ever reached from an administrator-gated controller**, so
 * it deliberately does not apply `MaintenanceVisibility`. Anything that needs a
 * scoped figure must go through the query object instead — an unscoped count
 * behind a technician surface is exactly the leak the module's design forbids.
 */
class MaintenanceMetrics
{
    private const DEFAULT_LEAD_DAYS = 7;

    private const DEFAULT_INTERVAL_DAYS = 90;

    /**
     * The configured preventive cadence (SRS FR-MNT-007).
     *
     * @return array{interval_days: int, lead_days: int}
     */
    public function cadence(): array
    {
        return [
            'interval_days' => $this->setting('maintenance.default_interval_days', self::DEFAULT_INTERVAL_DAYS),
            'lead_days' => $this->setting('maintenance.reminder_days', self::DEFAULT_LEAD_DAYS),
        ];
    }

    /**
     * Open workload and the preventive posture.
     *
     * @return array<string, int>
     */
    public function posture(): array
    {
        $now = now();
        $lead = $this->cadence()['lead_days'];

        return [
            'open' => $this->open()->count(),
            'scheduled' => $this->countOf(MaintenanceStatus::Scheduled),
            'in_progress' => $this->countOf(MaintenanceStatus::InProgress),
            'on_hold' => $this->countOf(MaintenanceStatus::OnHold),
            'overdue' => $this->open()
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<', $now)
                ->count(),
            'due_soon' => $this->open()
                ->whereNotNull('scheduled_for')
                ->whereBetween('scheduled_for', [$now, $now->copy()->addDays($lead)])
                ->count(),
            'unscheduled' => $this->open()->whereNull('scheduled_for')->count(),
            'lead_days' => $lead,
        ];
    }

    /**
     * Completed work and what it cost, over a trailing window.
     *
     * @return array{completed: int, downtime_minutes: int, labor_hours: float, cost: float, window_days: int}
     */
    public function throughput(int $windowDays = 30): array
    {
        $since = now()->subDays($windowDays);

        $query = MaintenanceRecord::query()
            ->where('status', MaintenanceStatus::Completed->value)
            ->where('completed_at', '>=', $since);

        return [
            'completed' => (clone $query)->count(),
            'downtime_minutes' => (int) (clone $query)->sum('downtime_minutes'),
            'labor_hours' => round((float) (clone $query)->sum('labor_hours'), 2),
            'cost' => round((float) (clone $query)->sum('cost'), 2),
            'window_days' => $windowDays,
        ];
    }

    /**
     * How the open workload splits by status.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function statusMix(): array
    {
        $counts = MaintenanceRecord::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return array_map(
            static fn (MaintenanceStatus $status): array => [
                'key' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ],
            MaintenanceStatus::cases(),
        );
    }

    /**
     * Preventive versus corrective, by maintenance type.
     *
     * @return list<array{key: string, label: string, count: int, is_preventive: bool}>
     */
    public function typeMix(): array
    {
        // `toBase()`: these rows are aggregates, not maintenance records, and
        // hydrating them as models would both waste the work and invite a
        // reader to treat `$row->slug` as a property of MaintenanceRecord.
        return MaintenanceRecord::query()
            ->join('maintenance_types', 'maintenance_types.id', '=', 'maintenance_records.maintenance_type_id')
            ->selectRaw('maintenance_types.slug, maintenance_types.name, maintenance_types.is_preventive, count(*) as aggregate')
            ->groupBy('maintenance_types.slug', 'maintenance_types.name', 'maintenance_types.is_preventive')
            ->orderByDesc('aggregate')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'key' => (string) $row->slug,
                'label' => (string) $row->name,
                'count' => (int) $row->aggregate,
                'is_preventive' => (bool) $row->is_preventive,
            ])
            ->all();
    }

    /**
     * Open maintenance per technician — the workload view.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function technicianWorkload(int $limit = 10): array
    {
        return MaintenanceRecord::query()
            ->join('users', 'users.id', '=', 'maintenance_records.technician_id')
            ->whereIn('maintenance_records.status', $this->openValues())
            ->selectRaw("users.uuid, concat(users.first_name, ' ', users.last_name) as full_name, count(*) as aggregate")
            ->groupBy('users.uuid', 'users.first_name', 'users.last_name')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'key' => (string) $row->uuid,
                'label' => (string) $row->full_name,
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }

    /* ------------------------------------------------------------ internals */

    /** @return Builder<MaintenanceRecord> */
    private function open(): Builder
    {
        return MaintenanceRecord::query()->whereIn('status', $this->openValues());
    }

    private function countOf(MaintenanceStatus $status): int
    {
        return MaintenanceRecord::query()->where('status', $status->value)->count();
    }

    /**
     * @return list<string>
     */
    private function openValues(): array
    {
        return array_map(
            static fn (MaintenanceStatus $status): string => $status->value,
            MaintenanceVisibility::OPEN_STATUSES,
        );
    }

    /**
     * A configurable integer from `system_settings`, falling back to the
     * documented default when the row is missing or unparseable.
     */
    private function setting(string $key, int $default): int
    {
        $value = SystemSetting::query()->where('key', $key)->value('value');

        if ($value === null || ! is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    /** Exposed for the console command, which reports against the same window. */
    public function dueWindow(): Carbon
    {
        return now()->addDays($this->cadence()['lead_days']);
    }
}
