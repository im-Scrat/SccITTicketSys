<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Domains\Assets\Services\Import\ImportCatalogResolver;
use App\Models\AssetImportCatalogEntry;
use App\Models\HardwareModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One catalog proposal and its decision (Phase 2.10 R6, D5). Catalog models
 * are addressed by id inside this payload only, as everywhere else in the
 * asset forms.
 *
 * @mixin AssetImportCatalogEntry
 */
class AssetImportCatalogEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $model = fn (?HardwareModel $m): ?array => $m === null ? null : ['id' => $m->id, 'label' => ImportCatalogResolver::modelLabel($m)];

        return [
            'id' => $this->uuid,
            'component_type' => $this->component_type->value,
            'component_label' => $this->component_type->label(),
            'manufacturer_name' => $this->manufacturer_name,
            'model_name' => $this->model_name,
            'model_number' => $this->model_number,
            'match' => $this->match->value,
            'match_label' => $this->match->label(),
            'matched_model' => $model($this->matchedModel),
            'suggestions' => $this->suggestions ?? [],
            'decision' => $this->decision->value,
            'decision_label' => $this->decision->label(),
            'chosen_model' => $model($this->chosenModel),
            'row_count' => $this->row_count,
            'decided' => $this->decided_by !== null,
        ];
    }
}
