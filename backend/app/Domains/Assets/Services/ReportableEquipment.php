<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Tickets\Http\Requests\StoreTicketRequest;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\PcStatus;
use App\Models\Asset;
use App\Models\HardwareModel;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * **The one definition of what a fault may be filed against** (SDD DD-78).
 *
 * Two places must agree on it exactly: the report form's room-scoped equipment
 * list ({@see AssetOptions::roomPcUnits()} / {@see AssetOptions::roomAssets()})
 * and {@see StoreTicketRequest}, which refuses anything the list would not
 * have offered. Written twice, they would drift — and a list filter without
 * the matching by-uuid check is an IDOR. So both start from these builders.
 *
 * **PC units:** every unit that is not retired. Archived (soft-deleted) units
 * are excluded by the model's own scope. This is also exactly the set the
 * global `/lookups/pc-units` offers, which now starts from here too.
 *
 * **Other assets** — deliberately narrow:
 *  - **in service only** ({@see AssetStatus::reportableValues()}: In Service,
 *    Maintenance, Out of Service). Spare stock, new and in-transit equipment
 *    is not in use in a room, and listing it room by room would make the form
 *    a located inventory of portable stock (owner decision 2026-10-16);
 *  - **not fitted inside a PC** — no open `pc_component_installations` row
 *    (`removal_date IS NULL`, the same definition the one-active-per-asset
 *    unique index uses). A fitted part's fault is the PC's fault, and its
 *    `current_room_id` is not kept up to date when it is fitted;
 *  - **of a reportable catalog type** ({@see ComponentType::reportableValues()}:
 *    whole equipment and attached devices, plus legacy `other`);
 *  - archived assets excluded by the model's own scope.
 *
 * The catalog filter reads `hardware_models` **with trashed** rows and joins
 * `hardware_components` directly (no soft-delete scope on a join): retiring a
 * catalog entry must not hide the live printers already bought under it —
 * `Asset::displayName()` copes with an archived model for the same reason.
 *
 * The global `/lookups/assets` does **not** use {@see assets()}: maintenance
 * picks internal parts and spare stock with it, which this set excludes.
 */
class ReportableEquipment
{
    /** @return Builder<PcUnit> */
    public function pcUnits(): Builder
    {
        return PcUnit::query()->where('pc_units.status', '<>', PcStatus::Retired->value);
    }

    /** @return Builder<Asset> */
    public function assets(): Builder
    {
        return Asset::query()
            ->whereIn('assets.status', AssetStatus::reportableValues())
            ->whereNotExists(fn (QueryBuilder $installation) => $installation
                ->select('pc_component_installations.id')
                ->from('pc_component_installations')
                ->whereColumn('pc_component_installations.asset_id', 'assets.id')
                ->whereNull('pc_component_installations.removal_date'))
            ->whereIn(
                'assets.hardware_model_id',
                HardwareModel::withTrashed()
                    ->select('hardware_models.id')
                    ->join('hardware_components', 'hardware_components.id', '=', 'hardware_models.hardware_component_id')
                    ->whereIn('hardware_components.component_type', ComponentType::reportableValues()),
            );
    }
}
