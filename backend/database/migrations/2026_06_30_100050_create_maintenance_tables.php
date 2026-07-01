<?php

use App\Enums\AssignmentStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\RepairImageType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technician workflow & maintenance: assignments, records, checklists,
 * repair images, notes, hardware replacements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('technician_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->enum('status', AssignmentStatus::values())->default(AssignmentStatus::Pending->value);
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampsTz();

            $table->index(['technician_id', 'status']);
            $table->index('ticket_id');
            $table->index('assigned_by');
            $table->index('status');
        });

        Schema::create('maintenance_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_preventive')->default(false);
            $table->unsignedBigInteger('default_checklist_template_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('default_checklist_template_id');
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('maintenance_type_id')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('maintenance_type_id');
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_template_id');
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->timestampsTz();

            $table->index('checklist_template_id');
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('technician_id');
            $table->unsignedBigInteger('maintenance_type_id');

            $table->string('title');
            $table->text('diagnosis')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('resolution')->nullable();
            $table->text('preventive_recommendation')->nullable();

            $table->integer('downtime_minutes')->nullable();
            $table->decimal('labor_hours', 6, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();

            $table->enum('status', MaintenanceStatus::values())->default(MaintenanceStatus::Scheduled->value);
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('maintenance_date')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['pc_unit_id', 'maintenance_date']);
            $table->index('ticket_id');
            $table->index('asset_id');
            $table->index('technician_id');
            $table->index('maintenance_type_id');
            $table->index('status');
        });
        DB::statement('ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_target_check CHECK (num_nonnulls(pc_unit_id, asset_id) >= 1)');
        DB::statement('ALTER TABLE maintenance_records ADD CONSTRAINT maintenance_records_metrics_check CHECK ((downtime_minutes IS NULL OR downtime_minutes >= 0) AND (labor_hours IS NULL OR labor_hours >= 0) AND (cost IS NULL OR cost >= 0))');

        Schema::create('maintenance_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_record_id');
            $table->unsignedBigInteger('checklist_template_item_id')->nullable();
            $table->string('item_label');
            $table->boolean('is_completed')->default(false);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index('maintenance_record_id');
            $table->index('checklist_template_item_id');
            $table->index('completed_by');
        });

        Schema::create('repair_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('maintenance_record_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->enum('image_type', RepairImageType::values());
            $table->string('disk')->default('local');
            $table->string('storage_path');
            $table->text('caption')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index('maintenance_record_id');
            $table->index('uploaded_by');
        });

        Schema::create('maintenance_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_record_id');
            $table->unsignedBigInteger('technician_id')->nullable();
            $table->text('body');
            $table->timestampsTz();

            $table->index('maintenance_record_id');
            $table->index('technician_id');
        });

        Schema::create('hardware_replacements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_record_id');
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('old_component_id')->nullable();
            $table->unsignedBigInteger('new_component_id')->nullable();
            $table->unsignedBigInteger('new_asset_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->text('replacement_reason')->nullable();
            $table->integer('warranty_months')->nullable();
            $table->timestampTz('replaced_at')->nullable();
            $table->timestampsTz();

            $table->index('maintenance_record_id');
            $table->index('pc_unit_id');
            $table->index('old_component_id');
            $table->index('new_component_id');
            $table->index('new_asset_id');
        });
        DB::statement('ALTER TABLE hardware_replacements ADD CONSTRAINT hardware_replacements_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE hardware_replacements ADD CONSTRAINT hardware_replacements_warranty_check CHECK (warranty_months IS NULL OR warranty_months >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_replacements');
        Schema::dropIfExists('maintenance_notes');
        Schema::dropIfExists('repair_images');
        Schema::dropIfExists('maintenance_checklists');
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('maintenance_types');
        Schema::dropIfExists('technician_assignments');
    }
};
