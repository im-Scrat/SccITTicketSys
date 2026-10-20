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

    protected function resolveSupplier(mixed $name): ?Supplier
    {
        return $this->present($name)
            ? Supplier::query()->where('name', (string) $name)->first()
            : null;
    }

    /**
     * The supplier for an asset form's answer (owner request 2026-10-20, SDD
     * DD-114): a chosen one by name, or one typed into "Other" — matched
     * case-insensitively against the existing list (archived rows revived,
     * since the unique index still counts them) and otherwise filed as a new
     * active supplier, so the next asset can simply choose it. The import never
     * comes through here: it does not create suppliers.
     */
    protected function resolveFormSupplier(mixed $name, mixed $other): ?Supplier
    {
        if (! $this->present($other)) {
            return $this->resolveSupplier($name);
        }

        $typed = trim((string) $other);

        $existing = Supplier::withTrashed()
            ->whereRaw('lower(name) = ?', [mb_strtolower($typed)])
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return Supplier::create(['name' => $typed, 'is_active' => true]);
    }

    /**
     * The "Other" category's description: kept only when the category is
     * "Other", trimmed so a whitespace-only value never reaches the
     * `assets_category_detail_check` constraint.
     *
     * @param  array<string, mixed>  $data
     */
    protected function categoryDetailFrom(array $data): ?string
    {
        if (($data['category'] ?? null) !== ComponentType::Other->value || ! $this->present($data['category_detail'] ?? null)) {
            return null;
        }

        return trim((string) $data['category_detail']);
    }

    protected function resolveHardwareModel(mixed $id): ?HardwareModel
    {
        return $this->present($id)
            ? HardwareModel::query()->find((int) $id)
            : null;
    }

    /**
     * The catalog model for an asset form's answer (owner request 2026-10-19):
     * a model typed into "Other" is looked up or filed in the catalog, a chosen
     * one is used as is, and no answer at all is the shared "Unspecified model".
     *
     * `hardware_models` requires a component, so typed and unspecified models
     * are filed under one shared `ComponentType::Other` component rather than
     * inventing one per entry. A typed model then appears in the catalog list
     * like any other, so the next asset of that model can simply choose it.
     * Soft-deleted rows are revived rather than duplicated, because the
     * (component, model_name) unique index still counts them.
     */
    protected function resolveFormHardwareModel(mixed $id, mixed $other): HardwareModel
    {
        if ($this->present($other)) {
            return $this->catalogModel(trim((string) $other));
        }

        return $this->resolveHardwareModel($id) ?? $this->catalogModel(HardwareModel::UNSPECIFIED);
    }

    private function catalogModel(string $name): HardwareModel
    {
        $component = HardwareComponent::withTrashed()->firstOrCreate(
            ['manufacturer_id' => null, 'name' => HardwareComponent::OTHER_MANUAL],
            ['component_type' => ComponentType::Other->value],
        );
        if ($component->trashed()) {
            $component->restore();
        }

        $model = HardwareModel::withTrashed()->firstOrCreate([
            'hardware_component_id' => $component->getKey(),
            'model_name' => $name,
        ]);
        if ($model->trashed()) {
            $model->restore();
        }

        return $model;
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
