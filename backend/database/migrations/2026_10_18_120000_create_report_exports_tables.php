<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.10 R3 — queued report documents and their email deliveries
 * (SDD DD-91/DD-92; SRS FR-RPT-004).
 *
 * `report_exports` is one requested document: what was asked for (the report,
 * the validated filters, the options, and the instant it reports up to — so a
 * queued job never re-reads the clock and renders exactly what was on screen),
 * where it is, and the facts that prove which file was produced (size, sha256,
 * row count, whether a budget cut it short). The file itself lives on the
 * private disk and expires after `reports.exports.retention_hours`; the row
 * stays, marked `expired`, as the record that it existed.
 *
 * `report_email_deliveries` is one recipient's copy of an emailed export.
 * `is_external` is the server's own classification at request time, the one
 * the administrator confirmed.
 *
 * There is no narrative column: the AI-narrative table arrives in R5, and a
 * foreign key to a table a later release creates would make this migration
 * fail on a stack that deploys R3 first. R5 adds it.
 *
 * The CHECK lists are frozen here, as values (a migration must keep meaning
 * what it meant when it ran); a schema test keeps each equal to its enum.
 *
 * `down()` drops both tables.
 */
return new class extends Migration
{
    private const TYPES = ['ticket_overview', 'overall'];

    private const FORMATS = ['pdf', 'docx', 'xlsx', 'csv'];

    private const STATUSES = ['pending', 'processing', 'completed', 'failed', 'expired'];

    private const PROFILES = ['full', 'identifiers'];

    private const PURPOSES = ['download', 'email'];

    private const DELIVERY_STATUSES = ['queued', 'sent', 'failed'];

    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_type', 32);
            $table->string('format', 8);
            $table->string('status', 16)->default('pending');
            $table->string('profile', 16)->default('full');
            $table->string('purpose', 16)->default('download');
            $table->jsonb('filters');
            $table->jsonb('options');
            $table->timestampTz('as_of');
            $table->char('dedupe_key', 64);
            $table->string('disk', 32)->default('local');
            $table->string('path', 255)->nullable();
            $table->string('filename', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestampTz('last_downloaded_at')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->string('request_user_agent', 512)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at'], 'report_exports_user_created_index');
            $table->index('dedupe_key', 'report_exports_dedupe_key_index');
            $table->index(['status', 'expires_at'], 'report_exports_status_expires_index');
        });

        $this->check('report_exports', 'report_type', self::TYPES);
        $this->check('report_exports', 'format', self::FORMATS);
        $this->check('report_exports', 'status', self::STATUSES);
        $this->check('report_exports', 'profile', self::PROFILES);
        $this->check('report_exports', 'purpose', self::PURPOSES);
        // An emailed file never carries free text (identifier-only profile).
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_email_profile_check CHECK (purpose <> 'email' OR profile = 'identifiers')");

        Schema::create('report_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('report_export_id')->constrained('report_exports')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient', 254);
            $table->boolean('is_external');
            $table->string('status', 16)->default('queued');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['report_export_id', 'recipient'], 'report_email_deliveries_export_recipient_unique');
            $table->index(['requested_by', 'created_at'], 'report_email_deliveries_requester_created_index');
            $table->index(['status', 'created_at'], 'report_email_deliveries_status_created_index');
        });

        $this->check('report_email_deliveries', 'status', self::DELIVERY_STATUSES);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_email_deliveries');
        Schema::dropIfExists('report_exports');
    }

    /**
     * @param  list<string>  $values
     */
    private function check(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};
