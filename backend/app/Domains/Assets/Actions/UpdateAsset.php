<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\ResolvesAssetReferences;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\PcCondition;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Update a serialized asset's attributes (SRS FR-AST-002).
 *
 * Every changed field is captured as an old→new pair in the audit properties, so
 * the asset timeline can answer "who changed the serial number, and what was it
 * before?" — the same diff discipline `UpdateRoom` established in Phase 2.4.
 *
 * Two things deliberately do **not** happen here, because each has its own
 * audited write path and collapsing them would make the timeline lie:
 *  - **status** changes go through `ChangeAssetStatus` → `AssetLifecycle`, which
 *    also writes `asset_status_history` (FR-AST-005);
 *  - **room** changes go through `TransferAsset`, which also writes
 *    `asset_transfers` (FR-AST-006).
 */
class UpdateAsset
{
    use ResolvesAssetReferences;

    /** Plain scalar fields compared and written as-is. */
    private const SCALAR_FIELDS = [
        'asset_tag',
        'name',
        'serial_number',
        'barcode',
        'purchase_price',
        'purchase_date',
        'warranty_expiration',
        'notes',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Asset $asset, array $data, User $actor, Request $request): Asset
    {
        $asset->loadMissing(['hardwareModel.component', 'supplier']);

        $changes = [];
        $attributes = [];

        foreach (self::SCALAR_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $new = $data[$field];
            $old = $asset->{$field};

            // Dates and decimals stringify differently between cast and payload;
            // compare their string forms so a no-op edit is not logged as a change.
            if ($this->stringify($old) !== $this->stringify($new)) {
                $changes[$field] = ['from' => $this->stringify($old), 'to' => $this->stringify($new)];
            }

            $attributes[$field] = $new;
        }

        if (array_key_exists('condition', $data)) {
            $condition = PcCondition::from((string) $data['condition']);

            if ($condition !== $asset->condition) {
                $changes['condition'] = ['from' => $asset->condition->value, 'to' => $condition->value];
            }

            $attributes['condition'] = $condition->value;
        }

        if (array_key_exists('hardware_model', $data)) {
            $model = $this->resolveHardwareModel($data['hardware_model']);

            if ($model !== null && $model->getKey() !== $asset->hardware_model_id) {
                $changes['model'] = ['from' => $asset->hardwareModel?->model_name, 'to' => $model->model_name];
                $attributes['hardware_model_id'] = $model->getKey();
            }
        }

        if (array_key_exists('supplier', $data)) {
            $supplier = $this->resolveSupplier($data['supplier']);

            if ($supplier?->getKey() !== $asset->supplier_id) {
                $changes['supplier'] = ['from' => $asset->supplier?->name, 'to' => $supplier?->name];
                $attributes['supplier_id'] = $supplier?->getKey();
            }
        }

        if ($attributes === []) {
            return $asset;
        }

        DB::transaction(function () use ($asset, $attributes, $actor): void {
            $asset->fill([...$attributes, 'updated_by' => $actor->getKey()])->save();
        });

        $this->audit->activity(
            ActivityAction::AssetUpdated,
            actor: $actor,
            subject: $asset,
            properties: $changes === [] ? null : ['changes' => $changes],
            request: $request,
            module: 'assets',
            description: "Asset {$asset->asset_tag} updated",
        );

        return $asset->refresh()->load([
            'hardwareModel.component.manufacturer',
            'supplier',
            'currentRoom.floor.building',
            'assignedTechnician',
        ]);
    }

    /** Normalize a value for comparison and for the audit trail. */
    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
