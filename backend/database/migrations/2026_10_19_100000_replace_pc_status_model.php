<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2.11 — replace the PC status domain (SDD DD-100, owner decision
 * 2026-10-19).
 *
 * Six values (available, assigned, online, offline, under_maintenance,
 * retired) become three repair states: `available`, `pending_repair`,
 * `needs_fix`. "Unassigned" is derived from `room_id IS NULL` and is not
 * stored, so nothing is mapped to it.
 *
 * Unlike the additive DD-32 migrations, this one narrows a domain, so it must
 * move data. Every changed row gets an `activity_logs` entry (cause
 * `status_model_migration`), so no status information is discarded silently —
 * and those entries are exactly what `down()` reads to put it all back:
 *
 *  1. **Map** `pc_units.status` and `maintenance_records.pc_status_before`,
 *     trashed rows included (a soft-deleted row still has to satisfy the new
 *     CHECK, and `RestorePcUnit` can bring it back).
 *  2. **Archive retired machines.** "Retired" no longer exists; the existing
 *     archive (soft delete) is how a machine leaves service. A retired machine
 *     that `AssetGuard::pcUnitBlockers` would refuse to archive — installed
 *     parts or open tickets — is left live as `needs_fix` and its audit row
 *     says `skipped_archive`, for an administrator to resolve.
 *  3. **Backfill the ticket rule.** An `available` machine that has a ticket
 *     still awaiting repair becomes `pending_repair` — what
 *     `PcStatusAutomation` would have done had it existed when the ticket was
 *     opened. "Awaiting repair" is `is_open AND slug <> 'resolved'` (DD-100).
 *
 * Value lists are frozen here on purpose: a migration must not follow the live
 * enum. (The baseline migrations build their CHECKs from `PcStatus::values()`,
 * so a fresh `migrate` already lands on the new domain and steps 1–3 touch no
 * rows.)
 *
 * **`down()` is exact.** It reads the audit rows of the most recent `up()` run
 * and, for every machine and maintenance record that run changed, restores
 * the value it recorded as `from` — the original legacy status, even where a
 * machine was mapped and then backfilled. It un-archives only the machines
 * that run archived, and only while nothing has archived or restored them
 * since. A row changed again *after* the migration (by a person, a ticket or a
 * repair) is not rewound to the old value — that would erase real work — but
 * mapped to its nearest legacy value, as is any row the run never touched.
 * Every restore is audited with cause `status_model_rollback`.
 */
return new class extends Migration
{
    private const LEGACY = ['available', 'assigned', 'online', 'offline', 'under_maintenance', 'retired'];

    private const CURRENT = ['available', 'pending_repair', 'needs_fix'];

    /** @var array<string, string> */
    private const MAP = [
        'assigned' => 'available',
        'online' => 'available',
        'under_maintenance' => 'pending_repair',
        'offline' => 'needs_fix',
        'retired' => 'needs_fix',
    ];

    /** @var array<string, string> nearest legacy value, for rows the run did not record */
    private const REVERSE = [
        'pending_repair' => 'under_maintenance',
        'needs_fix' => 'offline',
    ];

    private const REASON = 'status_model_migration';

    private const ROLLBACK = 'status_model_rollback';

    private const PC = 'App\\Models\\PcUnit';

    private const MAINTENANCE = 'App\\Models\\MaintenanceRecord';

    private ?string $run = null;

    public function up(): void
    {
        $this->dropChecks();

        $now = now();
        $logs = [];
        // Every row of one run carries its id, so `down()` can tell this run
        // from an earlier one even when both share a timestamp.
        $this->run = (string) Str::uuid();

        // ── 1 + 2: map every machine, archiving the retired ones ─────────────
        $units = DB::table('pc_units')
            ->whereIn('status', array_keys(self::MAP))
            ->orderBy('id')
            ->get(['id', 'unit_code', 'pc_name', 'status', 'deleted_at']);

        foreach ($units as $unit) {
            $to = self::MAP[$unit->status];
            $properties = ['changes' => ['status' => ['from' => $unit->status, 'to' => $to]], 'cause' => self::REASON];
            $archive = false;

            if ($unit->status === 'retired' && $unit->deleted_at === null) {
                $blockers = $this->blockers((int) $unit->id);

                if (array_sum($blockers) === 0) {
                    $archive = true;
                } else {
                    $properties['skipped_archive'] = $blockers;
                }
            }

            DB::table('pc_units')->where('id', $unit->id)->update(array_filter([
                'status' => $to,
                'deleted_at' => $archive ? $now : null,
            ], fn ($v) => $v !== null));

            $logs[] = $this->log(self::PC, (int) $unit->id, 'pc_unit_status_changed', $properties, $now,
                "PC unit {$unit->unit_code} status {$unit->status} → {$to} (status model change)");

            if ($archive) {
                $logs[] = $this->log(self::PC, (int) $unit->id, 'pc_unit_archived', [
                    'unit_code' => $unit->unit_code,
                    'pc_name' => $unit->pc_name,
                    'status' => 'retired',
                    'cause' => self::REASON,
                ], $now, "PC unit {$unit->unit_code} archived (retired under the previous status model)");
            }
        }

        // ── 3: a ticket still awaiting repair means Pending Repair ───────────
        $awaiting = DB::table('pc_units')
            ->whereNull('deleted_at')
            ->where('status', 'available')
            ->whereExists(fn ($q) => $q->from('tickets')
                ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
                ->whereColumn('tickets.pc_unit_id', 'pc_units.id')
                ->where('ticket_statuses.is_open', true)
                ->where('ticket_statuses.slug', '<>', 'resolved'))
            ->orderBy('id')
            ->get(['id', 'unit_code']);

        foreach ($awaiting as $unit) {
            DB::table('pc_units')->where('id', $unit->id)->update(['status' => 'pending_repair']);

            $logs[] = $this->log(self::PC, (int) $unit->id, 'pc_unit_status_changed', [
                'changes' => ['status' => ['from' => 'available', 'to' => 'pending_repair']],
                'cause' => self::REASON,
                'reason' => 'open_ticket_awaiting_repair',
            ], $now, "PC unit {$unit->unit_code} marked Pending Repair (open ticket awaiting repair)");
        }

        // The remembered pre-repair status of a maintenance visit maps the same
        // way, so cancelling a visit started before this change still restores
        // a value the new domain accepts. Recorded per row, so `down()` can put
        // each one back exactly.
        $records = DB::table('maintenance_records')
            ->whereIn('pc_status_before', array_keys(self::MAP))
            ->orderBy('id')
            ->get(['id', 'pc_status_before']);

        foreach ($records as $record) {
            $to = self::MAP[$record->pc_status_before];
            DB::table('maintenance_records')->where('id', $record->id)->update(['pc_status_before' => $to]);

            $logs[] = $this->log(self::MAINTENANCE, (int) $record->id, 'maintenance_updated', [
                'changes' => ['pc_status_before' => ['from' => $record->pc_status_before, 'to' => $to]],
                'cause' => self::REASON,
            ], $now, "Remembered PC status {$record->pc_status_before} → {$to} (status model change)", 'maintenance');
        }

        foreach (array_chunk($logs, 200) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        $this->addChecks(self::CURRENT);
    }

    public function down(): void
    {
        $this->dropChecks();

        $now = now();
        $logs = [];
        $run = $this->lastRun();

        // ── machines the last run changed: back to exactly what it recorded ──
        foreach ($run['pcs'] as $id => $change) {
            $current = DB::table('pc_units')->where('id', $id)->first(['id', 'unit_code', 'status']);

            if ($current === null) {
                continue;
            }

            // Unchanged since the run → its original value; changed since → the
            // nearest legacy value, never an older value over newer work.
            $restore = $current->status === $change['to']
                ? $change['from']
                : (self::REVERSE[$current->status] ?? $current->status);

            if ($restore === $current->status) {
                continue;
            }

            DB::table('pc_units')->where('id', $id)->update(['status' => $restore]);
            $logs[] = $this->log(self::PC, (int) $id, 'pc_unit_status_changed', [
                'changes' => ['status' => ['from' => $current->status, 'to' => $restore]],
                'cause' => self::ROLLBACK,
                'exact' => $current->status === $change['to'],
            ], $now, "PC unit {$current->unit_code} status {$current->status} → {$restore} (status model rolled back)");
        }

        // ── only the machines the last run archived, if still as it left them ──
        foreach ($run['archived'] as $id => $logId) {
            $since = DB::table('activity_logs')
                ->where('subject_type', self::PC)
                ->where('subject_id', $id)
                ->whereIn('action', ['pc_unit_archived', 'pc_unit_restored'])
                ->where('id', '>', $logId)
                ->exists();

            $unit = DB::table('pc_units')->where('id', $id)->first(['id', 'unit_code', 'deleted_at']);

            if ($since || $unit === null || $unit->deleted_at === null) {
                continue;
            }

            DB::table('pc_units')->where('id', $id)->update(['deleted_at' => null]);
            $logs[] = $this->log(self::PC, (int) $id, 'pc_unit_restored', [
                'unit_code' => $unit->unit_code,
                'cause' => self::ROLLBACK,
            ], $now, "PC unit {$unit->unit_code} un-archived (status model rolled back)");
        }

        // ── maintenance records' remembered status, the same way ─────────────
        foreach ($run['maintenance'] as $id => $change) {
            $current = DB::table('maintenance_records')->where('id', $id)->value('pc_status_before');

            if (! is_string($current)) {
                continue;
            }

            $restore = $current === $change['to'] ? $change['from'] : (self::REVERSE[$current] ?? $current);

            if ($restore !== $current) {
                DB::table('maintenance_records')->where('id', $id)->update(['pc_status_before' => $restore]);
                $logs[] = $this->log(self::MAINTENANCE, (int) $id, 'maintenance_updated', [
                    'changes' => ['pc_status_before' => ['from' => $current, 'to' => $restore]],
                    'cause' => self::ROLLBACK,
                ], $now, "Remembered PC status {$current} → {$restore} (status model rolled back)", 'maintenance');
            }
        }

        // ── anything else still holding a new value: nearest legacy value ────
        foreach (self::REVERSE as $from => $to) {
            DB::table('pc_units')->where('status', $from)->update(['status' => $to]);
            DB::table('maintenance_records')->where('pc_status_before', $from)->update(['pc_status_before' => $to]);
        }

        foreach (array_chunk($logs, 200) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        $this->addChecks(self::LEGACY);
    }

    /**
     * What the most recent `up()` run recorded: per machine the first `from`
     * (the original legacy value) and the last `to` (what the run left), the
     * machines it archived (with the log row id), and per maintenance record
     * its `from`/`to`.
     *
     * A run that a rollback has already undone is not undone twice: if any
     * rollback row was written after the run's last row, there is nothing to
     * restore exactly (a later `up()` that changed no rows would otherwise
     * resurrect the older run's values).
     *
     * @return array{pcs: array<int, array{from: string, to: string}>, archived: array<int, int>, maintenance: array<int, array{from: string, to: string}>}
     */
    private function lastRun(): array
    {
        $out = ['pcs' => [], 'archived' => [], 'maintenance' => []];

        $last = DB::table('activity_logs')
            ->where('properties->cause', self::REASON)
            ->orderByDesc('id')
            ->first(['id', 'properties']);

        $run = $last !== null ? (json_decode((string) $last->properties, true)['run'] ?? null) : null;

        if (! is_string($run) || DB::table('activity_logs')
            ->where('properties->cause', self::ROLLBACK)
            ->where('id', '>', $last->id)
            ->exists()) {
            return $out;
        }

        $rows = DB::table('activity_logs')
            ->where('properties->cause', self::REASON)
            ->where('properties->run', $run)
            ->orderBy('id')
            ->get(['id', 'action', 'subject_type', 'subject_id', 'properties']);

        foreach ($rows as $row) {
            $properties = json_decode((string) $row->properties, true) ?: [];
            $id = (int) $row->subject_id;

            if ($row->subject_type === self::PC && $row->action === 'pc_unit_archived') {
                $out['archived'][$id] = (int) $row->id;

                continue;
            }

            $field = $row->subject_type === self::PC ? 'status' : 'pc_status_before';
            $change = $properties['changes'][$field] ?? null;

            if (! is_array($change) || ! isset($change['from'], $change['to'])) {
                continue;
            }

            $bucket = $row->subject_type === self::PC ? 'pcs' : 'maintenance';
            $out[$bucket][$id] = [
                'from' => $out[$bucket][$id]['from'] ?? (string) $change['from'],
                'to' => (string) $change['to'],
            ];
        }

        return $out;
    }

    /**
     * The same two refusals `AssetGuard::pcUnitBlockers` applies, in SQL — a
     * migration must not depend on application services.
     *
     * @return array{components: int, open_tickets: int}
     */
    private function blockers(int $pcUnitId): array
    {
        return [
            'components' => DB::table('pc_component_installations')
                ->where('pc_unit_id', $pcUnitId)
                ->where('installation_status', 'installed')
                ->count(),
            'open_tickets' => DB::table('tickets')
                ->join('ticket_statuses', 'ticket_statuses.id', '=', 'tickets.current_status_id')
                ->where('tickets.pc_unit_id', $pcUnitId)
                ->where('ticket_statuses.is_open', true)
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function log(string $subjectType, int $subjectId, string $action, array $properties, mixed $now, string $description, string $module = 'assets'): array
    {
        if ($properties['cause'] === self::REASON) {
            $properties['run'] = $this->run;
        }

        return [
            'user_id' => null,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => $now,
        ];
    }

    private function dropChecks(): void
    {
        DB::statement('ALTER TABLE pc_units DROP CONSTRAINT IF EXISTS pc_units_status_check');
        DB::statement('ALTER TABLE maintenance_records DROP CONSTRAINT IF EXISTS maintenance_records_pc_status_before_check');
    }

    /**
     * @param  list<string>  $values
     */
    private function addChecks(array $values): void
    {
        $allowed = "'".implode("','", $values)."'";

        DB::statement("ALTER TABLE pc_units ADD CONSTRAINT pc_units_status_check CHECK (status IN ({$allowed}))");
        // Nullable: only a visit that has actually started remembers a status;
        // `x IN (...)` is NULL-safe, so NULL passes either way.
        DB::statement("ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_pc_status_before_check CHECK (pc_status_before IN ({$allowed}))");
    }
};
