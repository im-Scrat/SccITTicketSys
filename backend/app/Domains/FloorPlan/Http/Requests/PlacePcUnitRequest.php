<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Requests;

use App\Models\FloorPlanPosition;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The point an administrator aimed a PC unit at (SRS FR-FP-003/009).
 *
 * Shape only. Whether the point is on *this* layout's canvas depends on the
 * layout, which is not resolved until after authorization — so the bounds
 * check lives in `PlacePcUnit`, not here. The limits below only keep the
 * numbers finite and sane before the action sees them: `numeric` alone would
 * accept `1e400`, which PHP reads as infinity.
 */
class PlacePcUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route gate has already required this; restated so the request
        // cannot be reused on a route that forgot it.
        return (bool) $this->user()?->can('manage', FloorPlanPosition::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'x' => ['required', 'numeric', 'min:0', 'max:100000'],
            'y' => ['required', 'numeric', 'min:0', 'max:100000'],
            'snap' => ['sometimes', 'boolean'],
        ];
    }
}
