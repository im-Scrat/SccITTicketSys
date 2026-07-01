<?php

use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Enums\QrStatus;
use App\Enums\ScanResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Computers (PC units), their spec snapshot, and QR verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('room_id')->nullable();

            $table->string('unit_code')->unique();
            $table->string('asset_tag')->nullable()->unique();
            $table->string('hostname')->nullable()->unique();
            $table->string('qr_identifier')->nullable()->unique();

            $table->string('pc_name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->string('mac_address')->nullable();

            $table->date('purchase_date')->nullable();
            $table->date('warranty_expiration')->nullable();

            $table->enum('status', PcStatus::values())->default(PcStatus::Available->value);
            $table->enum('current_condition', PcCondition::values())->default(PcCondition::Working->value);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('room_id');
            $table->index('status');
            $table->index('current_condition');
            $table->index('created_by');
            $table->index('updated_by');
        });
        DB::statement('ALTER TABLE pc_units ADD CONSTRAINT pc_units_warranty_check CHECK (warranty_expiration IS NULL OR purchase_date IS NULL OR warranty_expiration >= purchase_date)');

        Schema::create('pc_specifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pc_unit_id')->unique();
            $table->string('cpu')->nullable();
            $table->string('motherboard')->nullable();
            $table->string('ram')->nullable();
            $table->string('gpu')->nullable();
            $table->string('storage_primary')->nullable();
            $table->string('storage_secondary')->nullable();
            $table->string('power_supply')->nullable();
            $table->string('monitor')->nullable();
            $table->string('keyboard')->nullable();
            $table->string('mouse')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('bios_version')->nullable();
            $table->string('network_adapter')->nullable();
            $table->timestampsTz();
        });

        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('code')->unique();
            $table->text('payload')->nullable();
            $table->string('location_label')->nullable();
            $table->enum('status', QrStatus::values())->default(QrStatus::Active->value);
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('last_scanned_at')->nullable();
            $table->timestampsTz();

            $table->index('pc_unit_id');
            $table->index('asset_id');
        });
        DB::statement('ALTER TABLE qr_codes ADD CONSTRAINT qr_codes_target_check CHECK (num_nonnulls(pc_unit_id, asset_id) = 1)');

        Schema::create('qr_scan_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('qr_code_id')->nullable();
            $table->unsignedBigInteger('pc_unit_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('maintenance_record_id')->nullable();
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->enum('scan_result', ScanResult::values());
            $table->ipAddress('ip_address')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestampTz('scanned_at');
            $table->timestampTz('created_at')->nullable();

            $table->index('qr_code_id');
            $table->index('pc_unit_id');
            $table->index('asset_id');
            $table->index('maintenance_record_id');
            $table->index('scanned_by');
        });
        DB::statement('ALTER TABLE qr_scan_logs ADD CONSTRAINT qr_scan_logs_geo_check CHECK ((latitude IS NULL OR latitude BETWEEN -90 AND 90) AND (longitude IS NULL OR longitude BETWEEN -180 AND 180))');
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_scan_logs');
        Schema::dropIfExists('qr_codes');
        Schema::dropIfExists('pc_specifications');
        Schema::dropIfExists('pc_units');
    }
};
