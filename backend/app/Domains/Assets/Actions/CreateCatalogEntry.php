<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Exceptions\CatalogEntryBlocked;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\ComponentType;
use App\Models\HardwareComponent;
use App\Models\HardwareModel;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Adds a manufacturer → component → model chain to the hardware catalog
 * (Phase 2.10 R6, owner decision D5) — the catalog's first write path.
 *
 * Called only for a proposal an administrator approved, and only after the
 * import was confirmed. A **find-or-create** at every level, under an advisory
 * lock on the normalised names, so two imports (or a retried job) approving
 * "Epson L3210" produce one model, not two: the lock serialises them and the
 * second finds what the first made.
 *
 * The component is the catalog's generic entry for that category under that
 * manufacturer ("Epson · Printer"); the model is the specific product. An
 * archived manufacturer, component or model with the same name is never
 * revived or duplicated here ({@see CatalogEntryBlocked}): the unique indexes
 * include archived rows, and restoring one is a deliberate act elsewhere.
 *
 * The actor is re-checked first — the job that calls this runs long after the
 * request that approved it.
 */
final class CreateCatalogEntry
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function handle(
        ComponentType $type,
        ?string $manufacturerName,
        string $modelName,
        ?string $modelNumber,
        User $actor,
        Request $request,
        array $auditContext = [],
    ): HardwareModel {
        if (! $actor->isAdministrator() || ! $actor->hasPermissionTo('assets.create')) {
            throw new CatalogEntryBlocked('actor_not_authorised');
        }

        $manufacturerName = $manufacturerName !== null && trim($manufacturerName) !== '' ? trim($manufacturerName) : null;
        $modelName = trim($modelName);
        $modelNumber = $modelNumber !== null && trim($modelNumber) !== '' ? trim($modelNumber) : null;

        return DB::transaction(function () use ($type, $manufacturerName, $modelName, $modelNumber, $actor, $request, $auditContext): HardwareModel {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                'catalog:'.$type->value.'|'.mb_strtolower((string) $manufacturerName).'|'.mb_strtolower($modelName),
            ]);

            $created = [];

            $manufacturer = null;
            if ($manufacturerName !== null) {
                $manufacturer = Manufacturer::query()->withTrashed()->whereRaw('lower(name) = ?', [mb_strtolower($manufacturerName)])->first();
                if ($manufacturer?->trashed()) {
                    throw new CatalogEntryBlocked('manufacturer_archived');
                }
                if ($manufacturer === null) {
                    $manufacturer = Manufacturer::query()->create(['name' => $manufacturerName]);
                    $created[] = 'manufacturer';
                }
            }

            $componentName = $type->label();
            $component = HardwareComponent::query()->withTrashed()
                ->where('component_type', $type->value)
                ->where('manufacturer_id', $manufacturer?->getKey())
                ->whereRaw('lower(name) = ?', [mb_strtolower($componentName)])
                ->first();
            if ($component?->trashed()) {
                throw new CatalogEntryBlocked('component_archived');
            }
            if ($component === null) {
                $component = HardwareComponent::query()->create([
                    'component_type' => $type,
                    'manufacturer_id' => $manufacturer?->getKey(),
                    'name' => $componentName,
                ]);
                $created[] = 'component';
            }

            $model = HardwareModel::query()->withTrashed()
                ->where('hardware_component_id', $component->getKey())
                ->whereRaw('lower(model_name) = ?', [mb_strtolower($modelName)])
                ->first();
            if ($model?->trashed()) {
                throw new CatalogEntryBlocked('model_archived');
            }
            if ($model === null) {
                $model = HardwareModel::query()->create([
                    'hardware_component_id' => $component->getKey(),
                    'model_name' => $modelName,
                    'model_number' => $modelNumber,
                ]);
                $created[] = 'model';
            }

            if ($created !== []) {
                $this->audit->activity(
                    ActivityAction::CatalogEntryCreated,
                    actor: $actor,
                    subject: $model,
                    properties: [
                        'category' => $type->value,
                        'manufacturer' => $manufacturer?->name,
                        'model' => $model->model_name,
                        'created' => $created,
                        ...($auditContext !== [] ? ['import' => $auditContext] : []),
                    ],
                    request: $request,
                    module: 'assets',
                    description: 'Catalog entry '.trim(($manufacturer->name ?? '').' '.$model->model_name).' added',
                );
            }

            return $model;
        });
    }
}
