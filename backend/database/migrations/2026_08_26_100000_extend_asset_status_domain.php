<?php

use App\Enums\AssetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.5 — Asset Management: widen the `AssetStatus` CHECK domain.
 *
 * Additive migration on the baselined `assets` and `asset_status_history`
 * tables. No column is renamed, retyped or removed, and **every value that was
 * legal before stays legal** — the constraint is widened with `new` and
 * `out_of_service`, so no data migration is required (SDD DD-32).
 *
 * `AssetStatus` is the single source of truth: the baselined inventory migration
 * builds these CHECKs from `AssetStatus::values()`, so a fresh `migrate` already
 * yields the 9-value constraints. This migration reconciles databases that were
 * migrated before Phase 2.5 (7 values). It is the same technique DD-18 used to
 * add the `rejected` user status, which is why the PHP-enum-mirrored CHECK
 * design was chosen in the first place.
 *
 * Three constraints move together, because a history row must be able to record
 * a transition into — and out of — the two new states.
 */
return new class extends Migration
{
    /** The pre-Phase-2.5 domain, restored by down(). */
    private const ORIGINAL = ['in_stock', 'deployed', 'in_repair', 'reserved', 'in_transit', 'retired', 'disposed'];

    /** @var array<string, string> constraint name => column */
    private const CONSTRAINTS = [
        'assets|assets_status_check' => 'status',
        'asset_status_history|asset_status_history_from_status_check' => 'from_status',
        'asset_status_history|asset_status_history_to_status_check' => 'to_status',
    ];

    public function up(): void
    {
        $this->apply(AssetStatus::values());
    }

    public function down(): void
    {
        // Values added by this migration cannot survive the narrower constraint.
        // Normalize them to the closest pre-existing state before restoring it:
        // `new` stock has not been deployed yet, and an out-of-service unit is
        // awaiting attention — both read as `in_stock` under the original domain.
        DB::statement("UPDATE assets SET status = 'in_stock' WHERE status IN ('new', 'out_of_service')");
        DB::statement("UPDATE asset_status_history SET from_status = 'in_stock' WHERE from_status IN ('new', 'out_of_service')");
        DB::statement("UPDATE asset_status_history SET to_status = 'in_stock' WHERE to_status IN ('new', 'out_of_service')");

        $this->apply(self::ORIGINAL);
    }

    /**
     * @param  list<string>  $values
     */
    private function apply(array $values): void
    {
        $allowed = "'".implode("','", $values)."'";

        foreach (self::CONSTRAINTS as $target => $column) {
            [$table, $constraint] = explode('|', $target);

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$allowed}))");
        }

        // `from_status` is nullable (the very first history row has no origin);
        // `x IN (...)` is NULL-safe, so the CHECK passes for NULL either way.
    }
};
