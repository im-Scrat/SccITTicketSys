<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.5 — Asset Management: per-unit identity, custodianship, and the
 * indexes the enterprise directory needs.
 *
 * Additive migration on the baselined `assets` table. Nothing is renamed,
 * retyped or removed.
 *
 *  - `name` — an asset's name was previously *derived* from its catalog model
 *    (manufacturer + model_name), which cannot distinguish two identical
 *    printers. Nullable, so the derived name remains the fallback
 *    (`Asset::displayName()`) and no existing row needs backfilling.
 *  - `assigned_technician_id` — custodianship (FR-AST-002). `ON DELETE SET NULL`
 *    matches every other actor FK in the schema: archiving a user must never
 *    cascade-delete equipment.
 *
 * **Indexes.** The directory's global search (FR-AST-011) matches a term across
 * asset tag, serial, barcode, name, PC name, hostname, catalog model,
 * manufacturer, supplier, component and QR code. Those are unanchored `ILIKE
 * '%term%'` predicates, which a btree index cannot serve — so the text columns
 * get **GIN trigram** indexes instead, which is exactly what pg_trgm exists for.
 * Composite btree indexes cover the discrete status+location filters.
 *
 * Location and technician name matching rides `EXISTS` subqueries against
 * `buildings`/`floors`/`rooms`/`users`, which are bounded by the size of the
 * estate and the staff list and already carry their own keys.
 */
return new class extends Migration
{
    /**
     * table => columns receiving a GIN trigram index.
     *
     * @var array<string, list<string>>
     */
    private const TRIGRAM = [
        'assets' => ['asset_tag', 'serial_number', 'barcode', 'name'],
        'pc_units' => ['unit_code', 'pc_name', 'asset_tag', 'hostname', 'serial_number'],
        'hardware_models' => ['model_name', 'model_number'],
        'hardware_components' => ['name'],
        'manufacturers' => ['name'],
        'suppliers' => ['name'],
        'qr_codes' => ['code'],
    ];

    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('asset_tag');
            $table->unsignedBigInteger('assigned_technician_id')->nullable()->after('current_room_id');

            $table->index('assigned_technician_id');
            $table->foreign('assigned_technician_id')->references('id')->on('users')->nullOnDelete();

            // Directory filters: "in repair, in this room", "deployed anywhere".
            $table->index(['status', 'current_room_id'], 'assets_status_room_index');
            $table->index('warranty_expiration', 'assets_warranty_expiration_index');
        });

        Schema::table('pc_units', function (Blueprint $table): void {
            $table->index(['status', 'room_id'], 'pc_units_status_room_index');
            $table->index('warranty_expiration', 'pc_units_warranty_expiration_index');
        });

        // Idempotent: already present on this deployment, but a fresh volume or a
        // different environment must not depend on that.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::TRIGRAM as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(
                    "CREATE INDEX IF NOT EXISTS {$table}_{$column}_trgm ON {$table} USING gin ({$column} gin_trgm_ops)"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::TRIGRAM as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_trgm");
            }
        }

        Schema::table('pc_units', function (Blueprint $table): void {
            $table->dropIndex('pc_units_status_room_index');
            $table->dropIndex('pc_units_warranty_expiration_index');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex('assets_status_room_index');
            $table->dropIndex('assets_warranty_expiration_index');
            $table->dropForeign(['assigned_technician_id']);
            $table->dropIndex(['assigned_technician_id']);
            $table->dropColumn(['name', 'assigned_technician_id']);
        });

        // pg_trgm is intentionally not dropped: other objects may depend on it,
        // and dropping is unnecessary for a clean rebuild (same stance as the
        // baselined extensions migration).
    }
};
