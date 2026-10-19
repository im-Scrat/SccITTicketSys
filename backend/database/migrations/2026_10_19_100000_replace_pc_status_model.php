<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
 * move data. Every changed row gets an `activity_logs` entry, so no status
 * information is discarded silently:
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
 * `down()` maps the new values back onto the nearest legacy ones and restores
 * the six-value CHECKs. It does **not** un-archive the machines step 2
 * archived: un-archiving is a deliberate act with its own audit trail
 * (`RestorePcUnit`), not a schema side effect.
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

    /** @var array<string, string> */
    private const REVERSE = [
        'pending_repair' => 'under_maintenance',
        'needs_fix' => 'offline',
    ];

    private const REASON = 'status_model_migration';

    public function up(): void
    {
        $this->dropChecks();

        $now = now();
        $logs = [];

        // ── 1 + 2: map every machine, archiving the retired ones ─────────────
        $units = DB::table('pc_units')
            ->whereIn('status', array_keys(self::MAP))
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

            $logs[] = $this->log((int) $unit->id, 'pc_unit_status_changed', $properties, $now,
                "PC unit {$unit->unit_code} status {$unit->status} → {$to} (status model change)");

            if ($archive) {
                $logs[] = $this->log((int) $unit->id, 'pc_unit_archived', [
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
            ->get(['id', 'unit_code']);

        foreach ($awaiting as $unit) {
            DB::table('pc_units')->where('id', $unit->id)->update(['status' => 'pending_repair']);

            $logs[] = $this->log((int) $unit->id, 'pc_unit_status_changed', [
                'changes' => ['status' => ['from' => 'available', 'to' => 'pending_repair']],
                'cause' => self::REASON,
                'reason' => 'open_ticket_awaiting_repair',
            ], $now, "PC unit {$unit->unit_code} marked Pending Repair (open ticket awaiting repair)");
        }

        // The remembered pre-repair status of a maintenance visit maps the same
        // way, so cancelling a visit started before this change still restores
        // a value the new domain accepts.
        foreach (self::MAP as $from => $to) {
            DB::table('maintenance_records')->where('pc_status_before', $from)->update(['pc_status_before' => $to]);
        }

        foreach (array_chunk($logs, 200) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        $this->addChecks(self::CURRENT);
    }

    public function down(): void
    {
        $this->dropChecks();

        foreach (self::REVERSE as $from => $to) {
            DB::table('pc_units')->where('status', $from)->update(['status' => $to]);
            DB::table('maintenance_records')->where('pc_status_before', $from)->update(['pc_status_before' => $to]);
        }

        $this->addChecks(self::LEGACY);
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
    private function log(int $pcUnitId, string $action, array $properties, mixed $now, string $description): array
    {
        return [
            'user_id' => null,
            'action' => $action,
            'module' => 'assets',
            'description' => $description,
            'subject_type' => 'App\\Models\\PcUnit',
            'subject_id' => $pcUnitId,
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
