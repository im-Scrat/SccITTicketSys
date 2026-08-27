<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Http\Resources\AssetOptionResource;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Policies\PcUnitPolicy;
use App\Models\Asset;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the **narrow, non-administrator** equipment lookup (SDD DD-38).
 *
 * Authorized by `selectAsset` / `selectPcUnit` — abilities the *consuming
 * workflow's* permission grants (`tickets.create`, `maintenance.view`, …) — and
 * never by an `assets.*` permission. That is what lets a Teacher name the PC
 * they are reporting, and a Technician name the machine they are repairing,
 * without either of them gaining any access to the Asset Management module.
 *
 * The only parameter is a search term. There is no sort, no pagination, no
 * filter and no `trashed` — this is a form field, not a directory, and the
 * response ({@see AssetOptionResource}) is
 * labels only.
 *
 * @see AssetPolicy::selectAsset()
 * @see PcUnitPolicy::selectPcUnit()
 */
class AssetLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // One request class serves both lookup endpoints; which ability applies
        // follows from which one was called. Matched on the path rather than a
        // route name because these routes are not named, in keeping with the
        // rest of the API.
        return str_ends_with($this->path(), 'pc-units')
            ? $user->can('selectPcUnit', PcUnit::class)
            : $user->can('selectAsset', Asset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
