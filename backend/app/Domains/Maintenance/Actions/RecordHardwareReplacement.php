<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\AssetStatus;
use App\Enums\InstallationStatus;
use App\Models\Asset;
use App\Models\HardwareComponent;
use App\Models\HardwareReplacement;
use App\Models\MaintenanceRecord;
use App\Models\PcComponentInstallation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Record a part swapped during a visit and reconcile the PC's installation
 * history (SRS FR-MNT-006, AC-MNT-006; SDD DD-56).
 *
 * Four references and they mean different things: `old_component_id` and
 * `new_component_id` name the **generic catalogue** part, while `old_asset_id`
 * and `new_asset_id` name the specific **serialized units** that physically
 * moved. Only the serialized ones can be reconciled against
 * `pc_component_installations`, which is keyed on `asset_id` — and until DR-022
 * added `old_asset_id` there was no way to say which unit came out, which is
 * exactly why AC-MNT-006 was unimplementable.
 *
 * Both serialized sides are optional. A fan, a thermal pad or a cable is a real
 * replacement that was never a registered asset, and forcing a serialized
 * reference would either block those or invite fake asset rows.
 *
 * ── Why the reconciliation is safe ─────────────────────────────────────────
 *
 * `pc_component_installations_one_active_per_asset` is a partial unique index
 * on `(asset_id) WHERE removal_date IS NULL`. So a double-open is refused by the
 * **database**, not merely avoided by this code — which is what makes it safe to
 * close and open in one transaction rather than reasoning about interleaving.
 */
class RecordHardwareReplacement
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     */
    public function handle(MaintenanceRecord $record, array $data, User $actor, ?Request $request = null): HardwareReplacement
    {
        $oldAsset = $this->asset($data['old_asset'] ?? null);
        $newAsset = $this->asset($data['new_asset'] ?? null);
        $pcUnit = $record->pcUnit;

        return DB::transaction(function () use ($record, $data, $oldAsset, $newAsset, $pcUnit, $actor, $request): HardwareReplacement {
            $replacement = $record->hardwareReplacements()->create([
                'pc_unit_id' => $pcUnit?->getKey(),
                'old_component_id' => $this->component($data['old_component'] ?? null)?->getKey(),
                'new_component_id' => $this->component($data['new_component'] ?? null)?->getKey(),
                'old_asset_id' => $oldAsset?->getKey(),
                'new_asset_id' => $newAsset?->getKey(),
                'quantity' => (int) ($data['quantity'] ?? 1),
                'replacement_reason' => $data['reason'] ?? null,
                'warranty_months' => $data['warranty_months'] ?? null,
                'replaced_at' => now(),
            ]);

            // Only a record that actually targets a machine has an installation
            // history to reconcile. An asset-only record still stores the
            // replacement; it simply has no PC to move parts in and out of.
            if ($pcUnit !== null) {
                $this->closeInstallation($pcUnit->getKey(), $oldAsset, $actor);
                $this->openInstallation($pcUnit->getKey(), $newAsset, $actor, $data['reason'] ?? null);
            }

            $this->audit->activity(
                ActivityAction::MaintenanceHardwareReplaced,
                actor: $actor,
                subject: $record,
                properties: array_filter([
                    'quantity' => $replacement->quantity,
                    'old_asset' => $oldAsset?->asset_tag,
                    'new_asset' => $newAsset?->asset_tag,
                    'reason' => $replacement->replacement_reason,
                    'pc_unit' => $pcUnit?->unit_code,
                ], static fn (mixed $value): bool => $value !== null),
                request: $request,
                module: 'maintenance',
                description: "Hardware replaced during {$record->title}",
            );

            return $replacement->load(['oldComponent', 'newComponent', 'oldAsset', 'newAsset']);
        });
    }

    /**
     * Mark the removed unit as no longer installed (AC-MNT-006).
     *
     * Stamping `removal_date` is what releases the partial unique index, so the
     * same asset can later be fitted somewhere else. The asset's own status
     * moves to `in_repair`: it has come out of a machine during maintenance,
     * which is a truthful default and one `AssetLifecycle` permits from every
     * live state.
     */
    private function closeInstallation(int $pcUnitId, ?Asset $asset, User $actor): void
    {
        if ($asset === null) {
            return;
        }

        PcComponentInstallation::query()
            ->where('pc_unit_id', $pcUnitId)
            ->where('asset_id', $asset->getKey())
            ->whereNull('removal_date')
            ->update([
                'installation_status' => InstallationStatus::Removed->value,
                'removal_date' => now(),
                'updated_at' => now(),
            ]);

        // Not routed through AssetLifecycle: that service is the write path for
        // an *administrator's* deliberate lifecycle decision and demands
        // `assets.*`, which a technician does not hold (DD-38). This is a
        // consequence of maintenance, recorded with the maintenance audit entry
        // rather than an asset one.
        if ($asset->status->isLive()) {
            $asset->forceFill(['status' => AssetStatus::InRepair->value, 'updated_by' => $actor->getKey()])->save();
        }
    }

    /** Fit the replacement unit into the machine. */
    private function openInstallation(int $pcUnitId, ?Asset $asset, User $actor, ?string $remarks): void
    {
        if ($asset === null) {
            return;
        }

        PcComponentInstallation::query()->create([
            'pc_unit_id' => $pcUnitId,
            'asset_id' => $asset->getKey(),
            'installed_by' => $actor->getKey(),
            'installation_status' => InstallationStatus::Installed->value,
            'installation_date' => now(),
            'remarks' => $remarks,
        ]);

        $asset->forceFill(['status' => AssetStatus::Deployed->value, 'updated_by' => $actor->getKey()])->save();
    }

    private function asset(mixed $uuid): ?Asset
    {
        return is_string($uuid) && $uuid !== ''
            ? Asset::query()->where('uuid', $uuid)->first()
            : null;
    }

    private function component(mixed $id): ?HardwareComponent
    {
        return $id !== null && $id !== ''
            ? HardwareComponent::query()->find((int) $id)
            : null;
    }
}
