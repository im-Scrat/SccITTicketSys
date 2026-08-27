<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Models\PcSpecification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The 1:1 specification snapshot (SRS FR-PC-003).
 *
 * The field list is taken from {@see UpsertPcSpecification::FIELDS} rather than
 * written out again, so the read shape and the write shape can never drift apart
 * — adding a column to the editor automatically adds it here.
 *
 * @mixin PcSpecification
 */
class PcSpecificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fields = [];

        foreach (UpsertPcSpecification::FIELDS as $field) {
            $fields[$field] = $this->{$field};
        }

        return [
            ...$fields,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
