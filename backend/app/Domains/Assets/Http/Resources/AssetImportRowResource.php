<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Domains\Assets\Services\Import\ImportLocationMatcher;
use App\Models\AssetImport;
use App\Models\AssetImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One review row (Phase 2.10 R6): its problems first, then each value "as it
 * will be saved" beside the cell it came from, with where it came from. The
 * created record, once there is one, by uuid only.
 *
 * Expects the row's `import` relation, location and catalog entry loaded.
 *
 * @mixin AssetImportRow
 */
class AssetImportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AssetImport $import */
        $import = $this->import;
        $original = [];
        foreach ($import->mapping ?? [] as $index => $target) {
            $value = $this->raw[(string) $index] ?? null;
            if ($value !== null && $value !== '') {
                $original[$target] = $value;
            }
        }

        $created = null;
        if ($this->created_pc_unit_id !== null && $this->createdPcUnit !== null) {
            $created = ['type' => 'pc_unit', 'id' => $this->createdPcUnit->uuid, 'label' => $this->createdPcUnit->unit_code.' · '.$this->createdPcUnit->pc_name];
        } elseif ($this->created_asset_id !== null && $this->createdAsset !== null) {
            $created = ['type' => 'asset', 'id' => $this->createdAsset->uuid, 'label' => $this->createdAsset->asset_tag];
        }

        $corrections = [];
        foreach ($this->corrections ?? [] as $target => $correction) {
            $corrections[$target] = ['value' => $correction['value'] ?? null, 'at' => $correction['at'] ?? null, 'assign_anyway' => $correction['assign_anyway'] ?? null];
        }

        $location = $this->location;
        $entry = $this->catalogEntry;

        return [
            'row_number' => $this->row_number,
            'entity_type' => $this->entity_type?->value,
            'entity_label' => $this->entity_type?->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_excluded' => $this->is_excluded,
            'outcome' => $this->outcome?->value,
            'outcome_label' => $this->outcome?->label(),
            'failure_code' => $this->failure_code,
            'previous_failure_code' => $this->previous_failure_code,
            'issues' => $this->issues ?? [],
            'acknowledged' => $this->acknowledged ?? [],
            'values' => (object) ($this->payload ?? []),
            'sources' => (object) ($this->sources ?? []),
            'original' => (object) $original,
            'corrections' => (object) $corrections,
            'location' => $location === null ? null : [
                'id' => $location->uuid,
                'source_text' => $location->source_text,
                'resolution' => $location->resolution->value,
                'room' => $location->room !== null ? ImportLocationMatcher::roomLabel($location->room) : null,
            ],
            'catalog' => $entry === null ? null : [
                'id' => $entry->uuid,
                'label' => trim(($entry->manufacturer_name ?? '').' '.$entry->model_name).' · '.$entry->component_type->label(),
                'decision' => $entry->decision->value,
            ],
            'created' => $created,
        ];
    }
}
