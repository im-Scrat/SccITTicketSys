<?php

use App\Enums\AnnouncementAudience;
use App\Enums\AuditEvent;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\LoginStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Enums\WidgetType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administration, notifications, audit & system configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('user_id');
            $table->enum('type', NotificationType::values());
            $table->string('title');
            $table->text('message')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('action_url')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('channel', NotificationChannel::values());
            $table->enum('notification_type', NotificationType::values());
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['user_id', 'channel', 'notification_type'], 'notification_preferences_unique');
            $table->index('user_id');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title');
            $table->text('content');
            $table->enum('audience', AnnouncementAudience::values())->default(AnnouncementAudience::All->value);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pinned')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('created_by');
            $table->index('is_active');
        });
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_date_order_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('module')->nullable();
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('properties')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('user_id');
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->enum('event', AuditEvent::values());
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('user_id');
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestampTz('login_at')->nullable();
            $table->timestampTz('logout_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->enum('login_status', LoginStatus::values());

            $table->index(['user_id', 'login_at']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group')->default('general');
            $table->string('key')->unique();
            $table->string('label')->nullable();
            $table->jsonb('value')->nullable();
            $table->string('type')->default('string');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_protected')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();

            $table->index('group');
            $table->index('is_public');
            $table->index('created_by');
            $table->index('updated_by');
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->enum('widget_type', WidgetType::values());
            $table->jsonb('configuration')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();

            $table->index('user_id');
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index('created_by');
        });
        DB::statement('ALTER TABLE maintenance_windows ADD CONSTRAINT maintenance_windows_date_order_check CHECK (ends_at > starts_at)');

        Schema::create('backup_history', function (Blueprint $table) {
            $table->id();
            $table->enum('backup_type', BackupType::values());
            $table->enum('status', BackupStatus::values())->default(BackupStatus::Pending->value);
            $table->string('filename')->nullable();
            $table->string('storage_location')->nullable();
            $table->string('disk')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_history');
        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('login_history');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
