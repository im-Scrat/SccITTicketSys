<?php

use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\DisposalMethod;
use App\Enums\InstallationStatus;
use App\Enums\PcCondition;
use App\Enums\ProcurementStatus;
use App\Enums\StockTransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory & asset lifecycle.
 * Catalog: manufacturers -> hardware_components -> hardware_models.
 * Stock:   assets (serialized) | consumables (quantity) + ledger & lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->string('website')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE manufacturers ALTER COLUMN support_email TYPE citext');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable()->unique();
            $table->string('contact_person')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE suppliers ALTER COLUMN email TYPE citext');

        Schema::create('hardware_components', function (Blueprint $table) {
            $table->id();
            $table->enum('component_type', ComponentType::values());
            $table->unsignedBigInteger('manufacturer_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['manufacturer_id', 'name']);
            $table->index('component_type');
        });

        Schema::create('hardware_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hardware_component_id');
            $table->string('model_name');
            $table->string('model_number')->nullable();
            $table->jsonb('specifications')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['hardware_component_id', 'model_name']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('asset_tag')->unique();
            $table->unsignedBigInteger('hardware_model_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('current_room_id')->nullable();

            $table->string('serial_number')->nullable()->unique();
            $table->string('barcode')->nullable();
            $table->enum('status', AssetStatus::values())->default(AssetStatus::InStock->value);
            $table->enum('condition', PcCondition::values())->default(PcCondition::Working->value);

            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expiration')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('hardware_model_id');
            $table->index('supplier_id');
            $table->index('current_room_id');
            $table->index('status');
            $table->index('created_by');
            $table->index('updated_by');
        });
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_purchase_price_check CHECK (purchase_price IS NULL OR purchase_price >= 0)');
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_warranty_check CHECK (warranty_expiration IS NULL OR purchase_date IS NULL OR warranty_expiration >= purchase_date)');

        Schema::create('consumables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hardware_model_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('current_room_id')->nullable();
            $table->string('name');
            $table->string('item_code')->unique();
            $table->string('unit_of_measure')->default('unit');
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('hardware_model_id');
            $table->index('supplier_id');
            $table->index('current_room_id');
        });
        DB::statement('ALTER TABLE consumables ADD CONSTRAINT consumables_quantities_check CHECK (quantity_on_hand >= 0 AND reorder_level >= 0 AND (unit_cost IS NULL OR unit_cost >= 0))');

        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consumable_id');
            $table->enum('transaction_type', StockTransactionType::values());
            $table->integer('quantity');
            $table->integer('balance_after')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['consumable_id', 'created_at']);
            $table->index('performed_by');
            $table->index('transaction_type');
        });
        DB::statement('ALTER TABLE stock_transactions ADD CONSTRAINT stock_transactions_quantity_check CHECK (quantity <> 0)');

        Schema::create('asset_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->enum('from_status', AssetStatus::values())->nullable();
            $table->enum('to_status', AssetStatus::values());
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['asset_id', 'created_at']);
            $table->index('changed_by');
        });

        Schema::create('pc_component_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pc_unit_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('installed_by')->nullable();
            $table->enum('installation_status', InstallationStatus::values())->default(InstallationStatus::Installed->value);
            $table->timestampTz('installation_date')->nullable();
            $table->timestampTz('removal_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampsTz();

            $table->index('pc_unit_id');
            $table->index('asset_id');
            $table->index('installed_by');
        });

        Schema::create('procurement_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('request_number')->unique();
            $table->unsignedBigInteger('requested_by');
            $table->enum('status', ProcurementStatus::values())->default(ProcurementStatus::Draft->value);
            $table->text('purpose')->nullable();
            $table->decimal('total_estimated_cost', 12, 2)->nullable();
            $table->date('needed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('requested_by');
            $table->index('approved_by');
            $table->index('status');
        });
        DB::statement('ALTER TABLE procurement_requests ADD CONSTRAINT procurement_requests_cost_check CHECK (total_estimated_cost IS NULL OR total_estimated_cost >= 0)');

        Schema::create('procurement_request_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('procurement_request_id');
            $table->unsignedBigInteger('hardware_model_id')->nullable();
            $table->string('description')->nullable();
            $table->integer('quantity');
            $table->decimal('estimated_unit_price', 12, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestampsTz();

            $table->index('procurement_request_id');
            $table->index('hardware_model_id');
        });
        DB::statement('ALTER TABLE procurement_request_items ADD CONSTRAINT procurement_request_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE procurement_request_items ADD CONSTRAINT procurement_request_items_price_check CHECK (estimated_unit_price IS NULL OR estimated_unit_price >= 0)');

        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('from_room_id')->nullable();
            $table->unsignedBigInteger('to_room_id')->nullable();
            $table->unsignedBigInteger('transferred_by')->nullable();
            $table->text('reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('transferred_at');
            $table->timestampsTz();

            $table->index('asset_id');
            $table->index('from_room_id');
            $table->index('to_room_id');
            $table->index('transferred_by');
        });

        Schema::create('disposal_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->enum('disposal_method', DisposalMethod::values());
            $table->text('disposal_reason')->nullable();
            $table->timestampTz('disposal_date');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('document_reference')->nullable();
            $table->decimal('salvage_value', 12, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestampsTz();

            $table->index('asset_id');
            $table->index('approved_by');
        });
        DB::statement('ALTER TABLE disposal_records ADD CONSTRAINT disposal_records_salvage_check CHECK (salvage_value IS NULL OR salvage_value >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('disposal_records');
        Schema::dropIfExists('asset_transfers');
        Schema::dropIfExists('procurement_request_items');
        Schema::dropIfExists('procurement_requests');
        Schema::dropIfExists('pc_component_installations');
        Schema::dropIfExists('asset_status_history');
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('consumables');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('hardware_models');
        Schema::dropIfExists('hardware_components');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('manufacturers');
    }
};
