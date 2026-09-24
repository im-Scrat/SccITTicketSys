<?php

use App\Enums\WorkSupportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-2.6b — technician work support requests (SRS §10.7.1 FR-WSR-001..014,
 * [DR-020](#); SDD DD-51/DD-54; Client decision [SRS OI-09], 2026-08-28,
 * schema approved 2026-08-29).
 *
 * Three new tables; **nothing existing is touched**. In particular
 * `procurement_requests` is neither overloaded nor redesigned — OI-09 decided
 * that explicitly, and `procurement_request_id` below is the whole of the
 * relationship (FR-WSR-013): a bridge reached only when an approval genuinely
 * requires buying something.
 *
 * ── Why this is not procurement ────────────────────────────────────────────
 *
 * `procurement_requests` models a purchasing lifecycle — a request number, an
 * estimated cost, `draft → submitted → approved/rejected → fulfilled/cancelled`
 * fixed by a CHECK — and it has no column pointing at a PC unit, a ticket or a
 * maintenance record. This is a technician standing at a machine asking for a
 * part and a decision, and two of the three decisions are *reschedule* and
 * *discuss in person*, which purchasing has no vocabulary for. Overloading one
 * table would make both vocabularies wrong (DD-51).
 *
 * ── Why one migration for three tables ─────────────────────────────────────
 *
 * They are one entity family with one lifetime: the items and the evidence are
 * meaningless without the request, both cascade from it, and `down()` drops
 * them in reverse in a single reversible step. This is the `create_maintenance_tables`
 * shape, not the one-concern-per-migration shape used for column additions.
 *
 * ── Where the decision *history* lives ─────────────────────────────────────
 *
 * Not here. The columns below hold the **current** decision; the audit trail of
 * how it got there — including any superseded schedule, which FR-WSR-004 forbids
 * overwriting silently — is written to `activity_logs` with before/after values
 * in `properties`. That is DD-55 restated: a fourth history table would be a
 * second place for the truth to live, and no FR-WSR asks for one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_support_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            /*
             * The anchor. FR-WSR-001 permits a request with no ticket, but never
             * one with no machine — "a request unrelated to any ticket shall
             * still be permitted, provided it names a PC unit" — so this is the
             * one NOT NULL target reference, and RESTRICT protects it. The app
             * soft-deletes PC units, so this only bites on a true hard delete,
             * which is exactly when a request should not be silently orphaned.
             */
            $table->unsignedBigInteger('pc_unit_id');

            // Job context when the work arose from one; both optional
            // (FR-WSR-001). SET NULL keeps the request readable after either is
            // purged — the request is still a real thing that happened.
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('maintenance_record_id')->nullable();

            // The submitter. RESTRICT for the reason `tickets.reporter_id` is
            // RESTRICT: a request nobody submitted is not a record, it is a gap.
            $table->unsignedBigInteger('technician_id');

            // FR-WSR-002: *why* it is needed to finish the job. Non-empty is a
            // database rule below, not merely a validation rule.
            $table->text('explanation');

            $table->enum('status', WorkSupportStatus::values())
                ->default(WorkSupportStatus::Submitted->value);

            /*
             * Decision metadata (FR-WSR-006/007/008). One deciding actor and one
             * timestamp serve all three decisions; the decision-specific fields
             * follow. `decided_by` is SET NULL so purging an administrator
             * account cannot destroy the request — which is why the CHECK below
             * keys on `decided_at` and not on `decided_by`.
             */
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();

            // Decision C — decline. Required whenever status is `declined`,
            // enforced by CHECK below as well as by the request and the service.
            $table->text('decline_reason')->nullable();

            // Decision A — approve and reschedule.
            $table->timestampTz('rescheduled_to')->nullable();
            $table->text('reschedule_reason')->nullable();
            // FR-WSR-006: the technician's acknowledgement, recorded when given.
            // Deliberately not a status — acknowledging does not change what the
            // request *is*.
            $table->timestampTz('acknowledged_at')->nullable();

            /*
             * Decision B — face-to-face. The record, not a calendar (OI-10):
             * a reason, and an optional proposed time. No availability, no
             * invitations, no reminders — a meeting-scheduling subsystem is a
             * product of its own, unjustified by a request that two people in
             * the same building resolve by walking to each other.
             */
            $table->text('clarification_reason')->nullable();
            $table->timestampTz('proposed_meeting_at')->nullable();

            // Withdrawal (FR-WSR-014). The actor is recorded either way, because
            // an Administrator may cancel on a technician's behalf.
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_note')->nullable();

            $table->timestampTz('closed_at')->nullable();

            // The purchasing bridge (FR-WSR-013) — and the entire extent of this
            // module's relationship with procurement. Approval of a support
            // request is not procurement approval (FR-AST-009).
            $table->unsignedBigInteger('procurement_request_id')->nullable();

            $table->timestampsTz();

            /*
             * No soft delete, deliberately. `cancelled` is the withdrawal path
             * the Client asked for and no FR-WSR describes archiving a request.
             * A `deleted_at` nobody sets is a column that invites a second,
             * unaudited way to make a request disappear.
             */

            // The technician's tracking page filters by owner and state
            // together; the administrator inbox filters by state and orders by
            // arrival. Two composite indexes, one per surface.
            $table->index(['technician_id', 'status'], 'work_support_requests_technician_status_index');
            $table->index(['status', 'created_at'], 'work_support_requests_status_created_index');

            $table->index('pc_unit_id');
            $table->index('ticket_id');
            $table->index('maintenance_record_id');
            $table->index('decided_by');
            $table->index('cancelled_by');
            $table->index('procurement_request_id');

            $table->foreign('pc_unit_id')->references('id')->on('pc_units')->restrictOnDelete();
            $table->foreign('ticket_id')->references('id')->on('tickets')->nullOnDelete();
            $table->foreign('maintenance_record_id')->references('id')->on('maintenance_records')->nullOnDelete();
            $table->foreign('technician_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('procurement_request_id')->references('id')->on('procurement_requests')->nullOnDelete();
        });

        /*
         * DR-020: "A decline shall be refused at the database level as well as
         * the application level when no reason is present."
         *
         * A blank test rather than a null check alone, because a reason of
         * `"   "` is the same absence with extra steps — and the FormRequest and
         * the lifecycle service both trim before they compare, so all three
         * layers must refuse the identical set of values.
         *
         * `!~ '^[[:space:]]*$'` and **not** `btrim(x) <> ''`: bare `btrim`
         * strips spaces only, so a reason of `"\t\n"` would slip past the
         * database while PHP's `trim()` — which also strips tabs, newlines,
         * carriage returns, vertical tabs and NULs — rejected it at the two
         * layers above. That gap is precisely the "database level as well as"
         * that DR-020 asks for, failing open. The POSIX class covers the whole
         * whitespace class in one expression.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE work_support_requests
            ADD CONSTRAINT work_support_requests_decline_reason_check
            CHECK (status <> 'declined' OR (decline_reason IS NOT NULL AND decline_reason !~ '^[[:space:]]*$'))
        SQL);

        // FR-WSR-002: a request with no explanation shall be refused. Same
        // reasoning and the same blank test — emptiness is a value, not a null.
        DB::statement(<<<'SQL'
            ALTER TABLE work_support_requests
            ADD CONSTRAINT work_support_requests_explanation_check
            CHECK (explanation !~ '^[[:space:]]*$')
        SQL);

        /*
         * A decision must carry the moment it was made.
         *
         * Keyed on `decided_at` and **not** on `decided_by`: that column is
         * `ON DELETE SET NULL`, so purging an administrator's account issues an
         * UPDATE against every request they decided — which re-evaluates this
         * CHECK. Naming `decided_by` here would make the constraint refuse the
         * cascade and turn a routine account deletion into a database error.
         * The deciding actor is preserved in `activity_logs` regardless
         * (FR-WSR-011), which is the durable record anyway.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE work_support_requests
            ADD CONSTRAINT work_support_requests_decided_at_check
            CHECK (status NOT IN ('approved', 'declined') OR decided_at IS NOT NULL)
        SQL);

        // A withdrawal must carry the moment it happened, for the same reason
        // and with the same avoidance of the nullable actor column.
        DB::statement(<<<'SQL'
            ALTER TABLE work_support_requests
            ADD CONSTRAINT work_support_requests_cancelled_at_check
            CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)
        SQL);

        Schema::create('work_support_request_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('work_support_request_id');

            /*
             * DR-020: a catalog model **or** a free-text description. Both may be
             * present (a catalogued part with a note about which variant); at
             * least one must be, or the line item names nothing — the CHECK
             * below is what makes that true rather than hoped for.
             *
             * SET NULL on the catalog reference: retiring a hardware model must
             * not erase the request that asked for it.
             */
            $table->unsignedBigInteger('hardware_model_id')->nullable();
            $table->string('description')->nullable();

            $table->integer('quantity');
            $table->text('remarks')->nullable();

            $table->timestampsTz();

            $table->index('work_support_request_id');
            $table->index('hardware_model_id');

            $table->foreign('work_support_request_id')
                ->references('id')->on('work_support_requests')->cascadeOnDelete();
            $table->foreign('hardware_model_id')
                ->references('id')->on('hardware_models')->nullOnDelete();
        });

        // FR-WSR-002 / DR-020: quantity > 0. Mirrors
        // `procurement_request_items_quantity_check` exactly — the same rule
        // about the same kind of line, stated the same way.
        DB::statement('ALTER TABLE work_support_request_items ADD CONSTRAINT work_support_request_items_quantity_check CHECK (quantity > 0)');

        // Same blank test as the explanation, for the same reason: a line item
        // described as `"\t"` names nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE work_support_request_items
            ADD CONSTRAINT work_support_request_items_named_check
            CHECK (hardware_model_id IS NOT NULL OR (description IS NOT NULL AND description !~ '^[[:space:]]*$'))
        SQL);

        /*
         * Optional supporting evidence (FR-WSR-002/003).
         *
         * Shaped after `asset_attachments` rather than invented: the same four
         * security columns (`original_filename`, `mime_type`, `file_size`,
         * `checksum`), the same private-disk `storage_path` that is never a
         * public URL, and the same `kind` split for display. It carries no
         * target CHECK because, unlike `asset_attachments` and `qr_codes`, it
         * has exactly one possible owner.
         *
         * FR-WSR-003 requires these to pass through "the system's single
         * attachment trust boundary only" — so the upload path reuses
         * `AttachmentSecurity::PROFILE_MAINTENANCE` (photographs and PDF) rather
         * than growing a fourth profile. DD-45 exists because two upload designs
         * had drifted until one of them was the weak one; a new profile with no
         * requirement behind it would be the beginning of the fourth.
         */
        Schema::create('work_support_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));

            $table->unsignedBigInteger('work_support_request_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->string('kind')->default('document');
            $table->string('disk')->default('local');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('checksum')->nullable();
            $table->string('caption')->nullable();

            $table->timestampsTz();

            $table->index('work_support_request_id');
            $table->index('uploaded_by');

            $table->foreign('work_support_request_id')
                ->references('id')->on('work_support_requests')->cascadeOnDelete();
            $table->foreign('uploaded_by')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE work_support_request_attachments ADD CONSTRAINT work_support_request_attachments_kind_check CHECK (kind IN ('image','document'))");

        // Mirrors `asset_attachments_file_size_check` and
        // `repair_images_file_size_check`: a negative byte count is not a
        // missing value, it is a corrupt one.
        DB::statement('ALTER TABLE work_support_request_attachments ADD CONSTRAINT work_support_request_attachments_file_size_check CHECK (file_size IS NULL OR file_size >= 0)');
    }

    public function down(): void
    {
        // Children first: both cascade from the request, but dropping in
        // dependency order keeps the rollback readable and independent of
        // cascade behaviour.
        Schema::dropIfExists('work_support_request_attachments');
        Schema::dropIfExists('work_support_request_items');
        Schema::dropIfExists('work_support_requests');
    }
};
