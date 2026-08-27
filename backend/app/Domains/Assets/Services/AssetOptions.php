<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services;

use App\Domains\Locations\Services\LocationOptions;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\Asset;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Manufacturer;
use App\Models\PcUnit;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Option lists for the Asset Management forms and filters — the
 * {@see LocationOptions} analogue.
 *
 * Two audiences, deliberately separated:
 *
 *  - {@see catalog()} and the filter option lists serve the **administrator**
 *    module. They carry catalog structure (which models belong to which
 *    component, which brand) because the create/edit form needs it.
 *  - {@see lookupAssets()} / {@see lookupPcUnits()} serve the **narrow
 *    non-admin lookup** (SDD DD-38): a technician naming the machine they are
 *    repairing, a teacher naming the PC they are reporting. Those return
 *    **labels only** — tag, name, location — and never status history, purchase
 *    price, supplier, custodian or archived rows. The lookup is authorized by
 *    the consuming workflow's permission, never by `assets.*`, so it cannot
 *    become a back door into the module.
 */
class AssetOptions
{
    /** Lookups are for picking one item out of a form field, not browsing. */
    private const LOOKUP_LIMIT = 50;

    /**
     * Everything the asset create/edit form needs in one round trip.
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return [
            'statuses' => $this->statusOptions(),
            'conditions' => $this->conditionOptions(),
            'categories' => $this->categoryOptions(),
            'manufacturers' => $this->manufacturers(),
            'suppliers' => $this->suppliers(),
            'models' => $this->models(),
            'technicians' => $this->technicians(),
            'pc_statuses' => $this->pcStatusOptions(),
        ];
    }

    /**
     * Asset lifecycle statuses, carrying the tone the client renders so status
     * colour is decided once, on the server (DESIGN.md).
     *
     * @return list<array{value: string, label: string, tone: string, terminal: bool}>
     */
    public function statusOptions(): array
    {
        return array_map(static fn (AssetStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'tone' => $status->tone(),
            'terminal' => $status->isTerminal(),
        ], AssetStatus::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function pcStatusOptions(): array
    {
        return array_map(static fn (PcStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], PcStatus::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function conditionOptions(): array
    {
        return array_map(static fn (PcCondition $condition): array => [
            'value' => $condition->value,
            'label' => $condition->label(),
        ], PcCondition::cases());
    }

    /**
     * Categories, grouped so whole equipment (printers, UPS, network devices)
     * reads separately from the parts that go inside a PC.
     *
     * @return list<array{value: string, label: string, group: string}>
     */
    public function categoryOptions(): array
    {
        return array_map(static fn (ComponentType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'group' => $type->group(),
        ], ComponentType::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function manufacturers(): array
    {
        return Manufacturer::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Manufacturer $m): array => [
                'value' => $m->name,
                'label' => $m->name,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function suppliers(): array
    {
        return Supplier::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Supplier $s): array => [
                'value' => $s->name,
                'label' => $s->name,
            ])
            ->all();
    }

    /**
     * Catalog models, each carrying its component and brand so the form can
     * cascade "category → brand → model" without extra round trips. Addressed by
     * uuid is not possible here — `hardware_models` carries no uuid in the
     * baselined schema — so the stable public handle is the composite label plus
     * the numeric id, which never appears in a URL (only in a form payload).
     *
     * @return list<array<string, mixed>>
     */
    public function models(): array
    {
        return HardwareModel::query()
            ->with('component.manufacturer')
            ->orderBy('model_name')
            ->get()
            ->map(static fn (HardwareModel $model): array => [
                'value' => $model->getKey(),
                'label' => $model->model_name,
                'model_number' => $model->model_number,
                'category' => $model->component?->component_type?->value,
                'category_label' => $model->component?->component_type?->label(),
                'component' => $model->component?->name,
                'manufacturer' => $model->component?->manufacturer?->name,
                'specifications' => $model->specifications,
            ])
            ->all();
    }

    /**
     * Accounts eligible to hold an asset. Restricted to the technician and
     * administrator roles: custodianship is an IT-staff responsibility, and
     * offering every account would invite assigning a printer to a teacher.
     *
     * @return list<array{value: string, label: string, role: string}>
     */
    public function technicians(): array
    {
        return User::query()
            ->whereIn('role_id', Role::query()->select('id')->whereIn('slug', ['technician', 'administrator']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->with('role:id,slug,name')
            ->get()
            ->map(static function (User $user): array {
                $role = $user->getRelationValue('role');

                return [
                    'value' => $user->uuid,
                    'label' => $user->fullName(),
                    'role' => $role !== null ? (string) $role->name : '',
                ];
            })
            ->all();
    }

    /**
     * Catalog components, optionally narrowed to one category — backs the
     * cascading create form.
     *
     * @return list<array<string, mixed>>
     */
    public function components(?ComponentType $type = null): array
    {
        return HardwareComponent::query()
            ->with('manufacturer')
            ->when($type !== null, fn (Builder $q): Builder => $q->where('component_type', $type->value))
            ->orderBy('name')
            ->get()
            ->map(static fn (HardwareComponent $component): array => [
                'value' => $component->getKey(),
                'label' => $component->name,
                'category' => $component->component_type->value,
                'category_label' => $component->component_type->label(),
                'manufacturer' => $component->manufacturer?->name,
            ])
            ->all();
    }

    /* -------------------------------------------------- narrow lookup (DD-38) */

    /**
     * Label-only asset options for a non-administrator form field.
     *
     * Deliberately minimal: identity and place, nothing operational. Archived and
     * disposed assets are excluded — you cannot report a fault on something that
     * has left the estate.
     *
     * @return list<array{id: string, label: string, asset_tag: string, location: string|null}>
     */
    public function lookupAssets(?string $search = null): array
    {
        return Asset::query()
            ->with(['hardwareModel', 'currentRoom.floor.building'])
            ->whereNotIn('status', [AssetStatus::Disposed->value, AssetStatus::Retired->value])
            ->when($this->term($search) !== null, function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $search)).'%';

                $query->where(function (Builder $q) use ($term): void {
                    $q->where('asset_tag', 'ILIKE', $term)
                        ->orWhere('name', 'ILIKE', $term)
                        ->orWhere('serial_number', 'ILIKE', $term);
                });
            })
            ->orderBy('asset_tag')
            ->limit(self::LOOKUP_LIMIT)
            ->get()
            ->map(fn (Asset $asset): array => [
                'id' => $asset->uuid,
                'label' => $asset->displayName().' ('.$asset->asset_tag.')',
                'asset_tag' => $asset->asset_tag,
                'location' => $this->roomLabel($asset->currentRoom),
            ])
            ->all();
    }

    /**
     * Label-only PC options for a non-administrator form field — the field a
     * teacher uses to say which machine is broken (FR-TKT, FR-LOC-011 sibling).
     *
     * @return list<array{id: string, label: string, unit_code: string, location: string|null}>
     */
    public function lookupPcUnits(?string $search = null): array
    {
        return PcUnit::query()
            ->with('room.floor.building')
            ->where('status', '<>', PcStatus::Retired->value)
            ->when($this->term($search) !== null, function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $search)).'%';

                $query->where(function (Builder $q) use ($term): void {
                    $q->where('unit_code', 'ILIKE', $term)
                        ->orWhere('pc_name', 'ILIKE', $term)
                        ->orWhere('asset_tag', 'ILIKE', $term)
                        ->orWhere('hostname', 'ILIKE', $term);
                });
            })
            ->orderBy('unit_code')
            ->limit(self::LOOKUP_LIMIT)
            ->get()
            ->map(fn (PcUnit $unit): array => [
                'id' => $unit->uuid,
                'label' => $unit->pc_name.' ('.$unit->unit_code.')',
                'unit_code' => $unit->unit_code,
                'location' => $this->roomLabel($unit->room),
            ])
            ->all();
    }

    private function term(?string $search): ?string
    {
        $search = $search !== null ? trim($search) : '';

        return $search === '' ? null : $search;
    }

    private function roomLabel(mixed $room): ?string
    {
        if ($room === null) {
            return null;
        }

        $floor = $room->floor;

        $parts = array_values(array_filter([
            $floor?->building?->name,
            $floor !== null ? ($floor->name !== '' ? $floor->name : 'Floor '.$floor->floor_number) : null,
            $room->name,
        ]));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
