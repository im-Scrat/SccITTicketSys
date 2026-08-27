<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\ResolvesAssetReferences;
use App\Domains\Assets\Services\AssetLifecycle;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetStatus;
use App\Enums\PcCondition;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Register a serialized asset (SRS FR-AST-002).
 *
 * The opening lifecycle state is written to `asset_status_history` in the same
 * transaction as the row itself, so an asset's history starts at creation rather
 * than at its first edit — without that, the timeline would begin mid-story
 * (FR-AST-005).
 *
 * `warranty_expiration >= purchase_date` and `purchase_price >= 0` are validated
 * in the FormRequest and guarded by the `assets_warranty_check` /
 * `assets_purchase_price_check` database constraints.
 */
class CreateAsset
{
    use ResolvesAssetReferences;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AssetLifecycle $lifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, Request $request): Asset
    {
        $status = AssetStatus::from((string) ($data['status'] ?? AssetStatus::New->value));
        $condition = PcCondition::from((string) ($data['condition'] ?? PcCondition::Working->value));

        $model = $this->resolveHardwareModel($data['hardware_model'] ?? null);
        $supplier = $this->resolveSupplier($data['supplier'] ?? null);
        $room = $this->resolveRoom($data['room'] ?? null);
        $technician = $this->resolveTechnician($data['technician'] ?? null);

        $asset = DB::transaction(function () use ($data, $status, $condition, $model, $supplier, $room, $technician, $actor): Asset {
            $asset = Asset::create([
                'asset_tag' => $data['asset_tag'],
                'name' => $data['name'] ?? null,
                'hardware_model_id' => $model?->getKey(),
                'supplier_id' => $supplier?->getKey(),
                'current_room_id' => $room?->getKey(),
                'assigned_technician_id' => $technician?->getKey(),
                'serial_number' => $data['serial_number'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'status' => $status->value,
                'condition' => $condition->value,
                'purchase_price' => $data['purchase_price'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? null,
                'warranty_expiration' => $data['warranty_expiration'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->lifecycle->recordInitial($asset, $actor);

            return $asset;
        });

        $asset->load([
            'hardwareModel.component.manufacturer',
            'supplier',
            'currentRoom.floor.building',
            'assignedTechnician',
        ]);

        $this->audit->activity(
            ActivityAction::AssetCreated,
            actor: $actor,
            subject: $asset,
            properties: [
                'asset_tag' => $asset->asset_tag,
                'name' => $asset->displayName(),
                'model' => $asset->hardwareModel?->model_name,
                'category' => $asset->hardwareModel?->component?->component_type?->value,
                'status' => $status->value,
                'status_label' => $status->label(),
                'room' => $asset->currentRoom?->name,
            ],
            request: $request,
            module: 'assets',
            description: "Asset {$asset->asset_tag} created",
        );

        return $asset;
    }
}
