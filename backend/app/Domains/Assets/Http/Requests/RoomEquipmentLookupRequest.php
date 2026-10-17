<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Http\Controllers\AssetLookupController;
use App\Domains\Assets\Policies\AssetPolicy;
use App\Domains\Assets\Policies\PcUnitPolicy;
use App\Models\Asset;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the **room-scoped** equipment lookup — the report form's "which
 * item has the problem?" step (SDD DD-78; {@see AssetLookupController::roomEquipment()}).
 *
 * **Why not {@see AssetLookupRequest}.** That class picks its ability from the
 * path (`…/pc-units` → `selectPcUnit`, anything else → `selectAsset`), so on
 * this route it would check only `selectAsset`. This endpoint answers with
 * both lists, so it requires **both** abilities: an account that may name a
 * PC but not another asset (e.g. floor-plan access alone) is refused outright
 * rather than handed half a list it would then misread as the whole room.
 *
 * The parameters are the room and a limit — and **no `search`**. The response
 * is one room, short enough to filter on the client, and a server-side
 * substring match is the one thing that could reach columns the label-only
 * response withholds (see `AssetOptions::roomAssets()`).
 *
 * @see AssetPolicy::selectAsset()
 * @see PcUnitPolicy::selectPcUnit()
 */
class RoomEquipmentLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('selectPcUnit', PcUnit::class)
            && $user->can('selectAsset', Asset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room' => ['required', 'string', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
