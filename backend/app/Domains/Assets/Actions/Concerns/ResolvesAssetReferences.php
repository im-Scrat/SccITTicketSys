<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions\Concerns;

use App\Enums\ComponentType;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Room;
use App\Models\Supplier;
use App\Models\User;

/**
 * Turns the public handles an asset payload carries into the models the write
 * path needs.
 *
 * Rooms and users are addressed by **uuid** and never by numeric id
 * (NFR-SEC-001) — a client that guesses `current_room_id: 4` gets nowhere.
 * Suppliers and manufacturers are addressed by their unique **name**, which is
 * the handle those tables actually expose: neither carries a uuid in the
 * baselined schema, and their names are already `UNIQUE`. Catalog models are the
 * one exception and are addressed by numeric id, because `hardware_models` has
 * no unique public handle at all — but that id only ever travels inside a form
 * payload, never in a URL.
 *
 * Every resolver returns `null` for an absent value and only queries when
 * something was actually supplied, so "field omitted" and "field cleared" stay
 * distinguishable by the caller.
 */
trait ResolvesAssetReferences
{
    protected function resolveRoom(mixed $uuid): ?Room
    {
        return $this->present($uuid)
            ? Room::query()->where('uuid', (string) $uuid)->first()
            : null;
    }

    protected function resolveTechnician(mixed $uuid): ?User
    {
        return $this->present($uuid)
            ? User::query()->where('uuid', (string) $uuid)->first()
            : null;
    }

    /**
     * `$manualName` comes from the form's "Other" text field. It takes priority
     * over `$name` because the two are mutually exclusive on the client; when set,
     * the supplier is looked up or created by that name rather than matched
     * against the catalog list.
     */
    protected function resolveSupplier(mixed $name, mixed $manualName = null): ?Supplier
    {
        if ($this->present($manualName)) {
            return Supplier::query()->firstOrCreate(['name' => trim((string) $manualName)]);
        }

        return $this->present($name)
            ? Supplier::query()->where('name', (string) $name)->first()
            : null;
    }

    /**
     * `$manualModelName` comes from the form's "Other" text field. `hardware_models`
     * requires a `hardware_component_id`, so a manually typed model is filed under a
     * single shared `ComponentType::Other` component rather than inventing one.
     */
    protected function resolveHardwareModel(mixed $id, mixed $manualModelName = null): ?HardwareModel
    {
        if ($this->present($manualModelName)) {
            return HardwareModel::query()->firstOrCreate([
                'hardware_component_id' => $this->otherHardwareComponent()->getKey(),
                'model_name' => trim((string) $manualModelName),
            ]);
        }

        return $this->present($id)
            ? HardwareModel::query()->find((int) $id)
            : null;
    }

    private function otherHardwareComponent(): HardwareComponent
    {
        return HardwareComponent::query()->firstOrCreate([
            'component_type' => ComponentType::Other->value,
            'name' => 'Other (manually entered)',
        ]);
    }

    /** A value the caller actually supplied, as opposed to null/blank. */
    protected function present(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! is_string($value) || trim($value) !== '';
    }
}
