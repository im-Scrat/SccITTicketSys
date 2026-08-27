<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Resources;

use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Locations\Http\Resources\LocationOptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A label-only equipment option for the **narrow non-admin lookup**
 * (SDD DD-38; the sibling of {@see LocationOptionResource}).
 *
 * This is the entire asset surface a Technician or Teacher can reach, so what it
 * omits matters more than what it carries. There is deliberately **no** status,
 * condition, purchase price, supplier, custodian, warranty, timestamps, audit or
 * archived row here — only enough to name a machine in a form: an identifier, a
 * readable label, and where it is.
 *
 * Authorized by {@see AssetPolicy::selectAsset()}, which is granted by the
 * consuming workflow's permission and never by `assets.*`. Because this resource
 * cannot express anything operational, widening the lookup's audience can never
 * leak the register.
 *
 * The underlying data is already shaped by
 * `AssetOptions::lookupAssets()` / `lookupPcUnits()`, so this wraps a plain
 * array rather than a model.
 */
class AssetOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $option */
        $option = $this->resource;

        return [
            'id' => $option['id'],
            'label' => $option['label'],
            'identifier' => $option['asset_tag'] ?? $option['unit_code'] ?? null,
            'location' => $option['location'] ?? null,
        ];
    }
}
