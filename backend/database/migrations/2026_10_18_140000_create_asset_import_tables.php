<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.10 R6 — the asset-import ledger (SRS FR-AST-016; SDD DD-96).
 *
 * An import is a durable record of one spreadsheet's journey into the register,
 * not a transient upload: every row the file held, what the administrator
 * decided about it, and what the save actually produced.
 *
 *  - `asset_imports` — the file (on the private disk, with its sha256), the
 *    column mapping and value meanings the administrator confirmed, the plan
 *    they confirmed (counts, so a changed plan is refused with 409), and the
 *    outcome the finalizer computed once. The review receipt columns
 *    (`reviewed_*`) belong to the post-import review (R7) but live here, so the
 *    review needs no migration of its own.
 *  - `asset_import_rows` — one per data row: `raw` (cells by column index, cut
 *    down to the mapped columns at finalisation and dropped once the file is
 *    deleted), `payload` (the values that would be saved), `corrections`
 *    (who changed what, and when), `issues`, the derived `status` and, after
 *    the save, the `outcome` and the record it created. `applied_fields` lists
 *    the targets whose value came from the file, a mapping or a correction —
 *    never a default — so the review can mark exactly those.
 *  - `asset_import_locations` — one decision per distinct location text.
 *  - `asset_import_catalog_entries` — one proposal per distinct catalog
 *    combination (owner decision D5): nothing is created before confirm.
 *
 * Rows are addressed by their row number inside an import, and location and
 * catalog decisions by their own uuid: no numeric id is ever addressable
 * (NFR-SEC-001).
 *
 * An import never updates an existing record. The created-record references
 * RESTRICT deletion, so the ledger can never claim a record that is gone; the
 * partial unique indexes make "one row created this record" a database fact.
 *
 * The CHECK lists are frozen here as values; a schema test keeps each one
 * equal to its enum. `down()` drops all four tables.
 */
return new class extends Migration
{
    private const MODES = ['pc_units', 'assets', 'mixed'];

    private const STATUSES = ['reading', 'mapping', 'validating', 'review', 'queued', 'importing', 'finished', 'cancelled', 'expired', 'unreadable'];

    private const OUTCOMES = ['complete', 'partial', 'nothing_new', 'failed', 'stopped'];

    private const EXTENSIONS = ['xlsx', 'csv'];

    private const DATE_ORDERS = ['dmy', 'mdy'];

    private const ROW_STATUSES = ['ready', 'needs_review', 'invalid', 'duplicate', 'excluded'];

    private const ROW_OUTCOMES = ['created', 'skipped_duplicate', 'failed', 'not_attempted'];

    private const ENTITY_TYPES = ['pc_unit', 'asset'];

    private const LOCATION_MATCHES = ['matched', 'ambiguous', 'unmatched', 'not_selectable'];

    private const LOCATION_RESOLUTIONS = ['pending', 'room', 'unassigned'];

    private const CATALOG_MATCHES = ['existing', 'new', 'suggested', 'blocked'];

    private const CATALOG_DECISIONS = ['pending', 'approved', 'existing', 'rejected'];

    public function up(): void
    {
        Schema::create('asset_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_mode', 16);
            $table->string('status', 16)->default('reading');
            $table->string('outcome', 16)->nullable();
            $table->unsignedInteger('revision')->default(1);

            $table->string('original_filename', 255);
            $table->string('extension', 8);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('disk', 32)->default('local');
            $table->string('path', 255)->nullable();
            $table->timestampTz('file_deleted_at')->nullable();

            $table->jsonb('source_options')->nullable();
            $table->jsonb('source_meta')->nullable();
            $table->jsonb('headers')->nullable();
            $table->jsonb('mapping')->nullable();
            $table->jsonb('value_mappings')->nullable();
            $table->jsonb('defaults')->nullable();
            $table->string('date_order', 8)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->jsonb('row_counts')->nullable();
            $table->string('error_code', 64)->nullable();

            $table->jsonb('plan')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirm_ip', 45)->nullable();
            $table->string('confirm_user_agent', 512)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('stop_requested_at')->nullable();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('result')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();

            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('reviewed_created_count')->nullable();

            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'updated_at'], 'asset_imports_status_updated_index');
            $table->index('sha256', 'asset_imports_sha256_index');
            $table->index(['created_by', 'created_at'], 'asset_imports_creator_created_index');
        });

        $this->check('asset_imports', 'entity_mode', self::MODES);
        $this->check('asset_imports', 'status', self::STATUSES);
        $this->check('asset_imports', 'outcome', self::OUTCOMES, nullable: true);
        $this->check('asset_imports', 'extension', self::EXTENSIONS);
        $this->check('asset_imports', 'date_order', self::DATE_ORDERS, nullable: true);

        Schema::create('asset_import_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('asset_import_id')->constrained('asset_imports')->cascadeOnDelete();
            $table->string('normalized', 512);
            $table->string('source_text', 512);
            $table->jsonb('parts');
            $table->string('match', 16);
            $table->jsonb('candidates')->nullable();
            $table->string('resolution', 16)->default('pending');
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->boolean('assign_anyway')->default(false);
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['asset_import_id', 'normalized'], 'asset_import_locations_import_normalized_unique');
        });

        $this->check('asset_import_locations', 'match', self::LOCATION_MATCHES);
        $this->check('asset_import_locations', 'resolution', self::LOCATION_RESOLUTIONS);
        // A room is chosen exactly when the resolution says so.
        DB::statement("ALTER TABLE asset_import_locations ADD CONSTRAINT asset_import_locations_room_check CHECK ((resolution = 'room') = (room_id IS NOT NULL))");

        Schema::create('asset_import_catalog_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('asset_import_id')->constrained('asset_imports')->cascadeOnDelete();
            $table->string('normalized', 768);
            $table->string('component_type', 32);
            $table->string('manufacturer_name', 255)->nullable();
            $table->string('model_name', 255);
            $table->string('model_number', 255)->nullable();
            $table->string('match', 16);
            $table->foreignId('matched_model_id')->nullable()->constrained('hardware_models')->restrictOnDelete();
            $table->jsonb('suggestions')->nullable();
            $table->string('decision', 16)->default('pending');
            $table->foreignId('chosen_model_id')->nullable()->constrained('hardware_models')->restrictOnDelete();
            $table->foreignId('created_model_id')->nullable()->constrained('hardware_models')->restrictOnDelete();
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['asset_import_id', 'normalized'], 'asset_import_catalog_entries_import_normalized_unique');
        });

        $this->check('asset_import_catalog_entries', 'match', self::CATALOG_MATCHES);
        $this->check('asset_import_catalog_entries', 'decision', self::CATALOG_DECISIONS);
        $this->check('asset_import_catalog_entries', 'component_type', $this->componentTypes());
        DB::statement("ALTER TABLE asset_import_catalog_entries ADD CONSTRAINT asset_import_catalog_entries_existing_check CHECK (decision <> 'existing' OR chosen_model_id IS NOT NULL)");

        Schema::create('asset_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_import_id')->constrained('asset_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('entity_type', 16)->nullable();
            $table->jsonb('raw')->nullable();
            $table->jsonb('cell_flags')->nullable();
            $table->jsonb('payload')->nullable();
            $table->jsonb('sources')->nullable();
            $table->jsonb('corrections')->nullable();
            $table->jsonb('acknowledged')->nullable();
            $table->jsonb('issues')->nullable();
            $table->string('status', 16)->default('needs_review');
            $table->boolean('is_excluded')->default(false);
            $table->foreignId('location_id')->nullable()->constrained('asset_import_locations')->nullOnDelete();
            $table->foreignId('catalog_entry_id')->nullable()->constrained('asset_import_catalog_entries')->nullOnDelete();
            $table->string('search_text', 1024)->nullable();

            $table->string('outcome', 24)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('previous_failure_code', 64)->nullable();
            $table->foreignId('created_pc_unit_id')->nullable()->constrained('pc_units')->restrictOnDelete();
            $table->foreignId('created_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('created_room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->jsonb('applied_fields')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['asset_import_id', 'row_number'], 'asset_import_rows_import_row_unique');
            $table->index(['asset_import_id', 'status'], 'asset_import_rows_import_status_index');
            $table->index(['asset_import_id', 'outcome'], 'asset_import_rows_import_outcome_index');
        });

        $this->check('asset_import_rows', 'status', self::ROW_STATUSES);
        $this->check('asset_import_rows', 'outcome', self::ROW_OUTCOMES, nullable: true);
        $this->check('asset_import_rows', 'entity_type', self::ENTITY_TYPES, nullable: true);
        // Excluded is the administrator's decision and wins over every issue.
        DB::statement("ALTER TABLE asset_import_rows ADD CONSTRAINT asset_import_rows_excluded_check CHECK (is_excluded = (status = 'excluded'))");
        // A created row names exactly one record, of its own type.
        DB::statement("ALTER TABLE asset_import_rows ADD CONSTRAINT asset_import_rows_created_check CHECK (
            (outcome = 'created') = (created_pc_unit_id IS NOT NULL OR created_asset_id IS NOT NULL)
            AND NOT (created_pc_unit_id IS NOT NULL AND created_asset_id IS NOT NULL)
        )");
        DB::statement('CREATE UNIQUE INDEX asset_import_rows_created_pc_unit_unique ON asset_import_rows (created_pc_unit_id) WHERE created_pc_unit_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX asset_import_rows_created_asset_unique ON asset_import_rows (created_asset_id) WHERE created_asset_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_import_rows');
        Schema::dropIfExists('asset_import_catalog_entries');
        Schema::dropIfExists('asset_import_locations');
        Schema::dropIfExists('asset_imports');
    }

    /**
     * The component types as they stood when this ran — read from the live
     * `hardware_components` CHECK rather than from the PHP enum, so the import
     * can never propose a category the catalog itself would refuse.
     *
     * @return list<string>
     */
    private function componentTypes(): array
    {
        $definition = (string) DB::table('pg_constraint')
            ->where('conname', 'hardware_components_component_type_check')
            ->value(DB::raw('pg_get_constraintdef(oid)'));

        preg_match_all("/'([^']+)'/", $definition, $matches);

        return $matches[1];
    }

    /**
     * @param  list<string>  $values
     */
    private function check(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
        $condition = $nullable ? "{$column} IS NULL OR {$column} IN ({$list})" : "{$column} IN ({$list})";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$condition})");
    }
};
