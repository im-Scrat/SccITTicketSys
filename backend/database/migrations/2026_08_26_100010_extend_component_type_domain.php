<?php

use App\Enums\ComponentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.5 — Asset Management: widen the `ComponentType` CHECK domain.
 *
 * Additive migration on the baselined `hardware_components` table. Adds the
 * whole-equipment categories the Client requires — system units, printers, UPS,
 * network devices, scanners and projectors — which the original 14-value domain
 * could only express as `peripheral`/`other` (SDD DD-32).
 *
 * Every pre-existing value stays legal, so no rows change. `ComponentType` is
 * the single source of truth; the baselined inventory migration builds this
 * CHECK from `ComponentType::values()`, so a fresh `migrate` already yields the
 * widened constraint and this migration reconciles older databases.
 */
return new class extends Migration
{
    /** The pre-Phase-2.5 domain, restored by down(). */
    private const ORIGINAL = [
        'cpu', 'motherboard', 'ram', 'gpu', 'storage', 'power_supply', 'monitor',
        'keyboard', 'mouse', 'network_adapter', 'cooler', 'chassis', 'peripheral', 'other',
    ];

    public function up(): void
    {
        $this->apply(ComponentType::values());
    }

    public function down(): void
    {
        // Categories added by this migration have no equivalent in the original
        // domain; `other` is the catch-all it provided for exactly this case.
        $added = "'".implode("','", array_diff(ComponentType::values(), self::ORIGINAL))."'";
        DB::statement("UPDATE hardware_components SET component_type = 'other' WHERE component_type IN ({$added})");

        $this->apply(self::ORIGINAL);
    }

    /**
     * @param  list<string>  $values
     */
    private function apply(array $values): void
    {
        $allowed = "'".implode("','", $values)."'";

        DB::statement('ALTER TABLE hardware_components DROP CONSTRAINT IF EXISTS hardware_components_component_type_check');
        DB::statement("ALTER TABLE hardware_components ADD CONSTRAINT hardware_components_component_type_check CHECK (component_type IN ({$allowed}))");
    }
};
