<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\GuardsAssetImport;
use App\Domains\Assets\Services\Import\AssetImportValidation;
use App\Domains\Assets\Services\Import\ImportCatalogResolver;
use App\Enums\AssetImportStatus;
use App\Enums\ImportCatalogDecision;
use App\Enums\ImportCatalogMatch;
use App\Models\AssetImport;
use App\Models\AssetImportCatalogEntry;
use App\Models\HardwareModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The administrator's decision on one catalog proposal (Phase 2.10 R6, owner
 * decision D5): approve it as a new entry (with its names edited if need be),
 * point it at a model already in the catalog, or decide not to import its
 * rows. Approval only records the decision — the entry is created after
 * confirm, never now.
 *
 * A proposal that collides with an archived entry cannot be approved as new:
 * the catalog's unique names include archived rows, and two "Epson L3210"s
 * would split one product's history.
 */
final class DecideAssetImportCatalogEntry
{
    use GuardsAssetImport;

    public function __construct(private readonly AssetImportValidation $validation) {}

    /**
     * @param  array{manufacturer_name?: string|null, model_name?: string|null, model_number?: string|null}  $names
     */
    public function handle(AssetImport $import, AssetImportCatalogEntry $entry, ImportCatalogDecision $decision, ?int $modelId, array $names, User $actor): AssetImportCatalogEntry
    {
        $this->assertStatus($import, [AssetImportStatus::Review]);

        $attributes = ['decision' => $decision, 'chosen_model_id' => null];

        if ($decision === ImportCatalogDecision::Existing) {
            $model = $modelId !== null ? HardwareModel::query()->with('component')->find($modelId) : null;
            if ($model === null || $model->component === null || $model->component->trashed() || $model->component->component_type !== $entry->component_type) {
                throw ValidationException::withMessages(['model' => 'Choose a '.$entry->component_type->label().' model from the catalog.']);
            }
            $attributes['chosen_model_id'] = $model->getKey();
        }

        if ($decision === ImportCatalogDecision::Approved) {
            $manufacturer = array_key_exists('manufacturer_name', $names) ? $this->clean($names['manufacturer_name']) : $entry->manufacturer_name;
            $modelName = array_key_exists('model_name', $names) ? $this->clean($names['model_name']) : $entry->model_name;
            $modelNumber = array_key_exists('model_number', $names) ? $this->clean($names['model_number']) : $entry->model_number;
            if ($modelName === null) {
                throw ValidationException::withMessages(['model_name' => 'Enter the model name.']);
            }

            // Edited names are matched again: they may now name an existing model.
            $match = (new ImportCatalogResolver)->match([
                'component_type' => $entry->component_type->value,
                'manufacturer' => $manufacturer,
                'model' => $modelName,
                'model_number' => $modelNumber,
            ]);
            if ($match['match'] === ImportCatalogMatch::Blocked) {
                throw ValidationException::withMessages(['decision' => 'This entry is archived in the catalog. Use an existing model instead, or don\'t import these rows.']);
            }

            $attributes += [
                'manufacturer_name' => $manufacturer,
                'model_name' => $modelName,
                'model_number' => $modelNumber,
                'match' => $match['match'] === ImportCatalogMatch::Existing ? ImportCatalogMatch::Existing : ImportCatalogMatch::New,
                'matched_model_id' => $match['model_id'],
            ];
        }

        DB::transaction(function () use ($import, $entry, $attributes, $decision, $actor): void {
            $entry->forceFill([
                ...$attributes,
                'decided_by' => $decision === ImportCatalogDecision::Pending ? null : $actor->getKey(),
                'decided_at' => $decision === ImportCatalogDecision::Pending ? null : now(),
            ])->save();
            $this->nextRevision($import);
            $this->validation->refresh($import);
        });

        return $entry->refresh();
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : ($value !== null ? mb_substr($value, 0, 255) : null);
    }
}
