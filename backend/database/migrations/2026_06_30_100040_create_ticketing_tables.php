<?php

use App\Enums\TicketSource;
use App\Enums\TicketUpdateType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticketing / ITSM: lookups, tickets and their activity, votes, comments, files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('default_priority_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index('default_priority_id');
        });

        Schema::create('ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->integer('level')->unique();
            $table->string('color');
            $table->integer('response_time_minutes')->nullable();
            $table->integer('resolution_time_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE ticket_priorities ADD CONSTRAINT ticket_priorities_level_check CHECK (level > 0)');
        DB::statement("ALTER TABLE ticket_priorities ADD CONSTRAINT ticket_priorities_color_check CHECK (color ~ '^#[0-9A-Fa-f]{6}$')");

        Schema::create('ticket_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('color');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_open')->default(true);
            $table->boolean('is_terminal')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE ticket_statuses ADD CONSTRAINT ticket_statuses_color_check CHECK (color ~ '^#[0-9A-Fa-f]{6}$')");

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('color')->nullable();
            $table->timestampsTz();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('ticket_number')->unique();

            $table->unsignedBigInteger('reporter_id');
            $table->unsignedBigInteger('assigned_technician_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('priority_id');
            $table->unsignedBigInteger('current_status_id');
            $table->unsignedBigInteger('duplicate_of_id')->nullable();

            $table->string('title');
            $table->text('description');
            $table->enum('source', TicketSource::values())->default(TicketSource::Web->value);

            $table->text('ai_summary')->nullable();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->integer('estimated_resolution_minutes')->nullable();
            $table->boolean('technician_required')->default(false);

            $table->integer('upvote_count')->default(0);
            $table->integer('comment_count')->default(0);
            $table->integer('attachment_count')->default(0);

            $table->timestampTz('first_response_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->timestampTz('response_due_at')->nullable();
            $table->timestampTz('resolution_due_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['current_status_id', 'created_at']);
            $table->index(['reporter_id', 'created_at']);
            $table->index(['pc_unit_id', 'created_at']);
            $table->index(['assigned_technician_id', 'current_status_id']);
            $table->index('category_id');
            $table->index('priority_id');
            $table->index('room_id');
            $table->index('duplicate_of_id');
            $table->index('created_by');
            $table->index('updated_by');
        });
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_no_self_duplicate_check CHECK (duplicate_of_id IS NULL OR duplicate_of_id <> id)');
        DB::statement('ALTER TABLE tickets ADD CONSTRAINT tickets_ai_confidence_check CHECK (ai_confidence IS NULL OR ai_confidence BETWEEN 0 AND 1)');

        Schema::create('ticket_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('tag_id');

            $table->primary(['ticket_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('ticket_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('update_type', TicketUpdateType::values());
            $table->text('body')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['ticket_id', 'created_at']);
            $table->index('user_id');
        });

        Schema::create('ticket_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('from_status_id')->nullable();
            $table->unsignedBigInteger('to_status_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['ticket_id', 'created_at']);
            $table->index('from_status_id');
            $table->index('to_status_id');
            $table->index('changed_by');
        });

        Schema::create('ticket_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['ticket_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_comment_id')->nullable();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_edited')->default(false);
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('ticket_id');
            $table->index('user_id');
            $table->index('parent_comment_id');
        });
        DB::statement('ALTER TABLE ticket_comments ADD CONSTRAINT ticket_comments_no_self_parent_check CHECK (parent_comment_id IS NULL OR parent_comment_id <> id)');

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('disk')->default('local');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('checksum')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('ticket_id');
            $table->index('uploaded_by');
        });
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_file_size_check CHECK (file_size IS NULL OR file_size >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('ticket_votes');
        Schema::dropIfExists('ticket_status_history');
        Schema::dropIfExists('ticket_updates');
        Schema::dropIfExists('ticket_tags');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('ticket_statuses');
        Schema::dropIfExists('ticket_priorities');
        Schema::dropIfExists('ticket_categories');
    }
};
