<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Requests;

use App\Models\RoomLayout;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new layout version's canvas (SRS FR-FP-001). `width`/`height`/`grid_size`
 * mirror `room_layouts_dimensions_check` — validated here too, so a bad value
 * is a 422 naming the field rather than a database constraint surfacing as a
 * 500. `background_image` is stored as given; there is no upload endpoint in
 * this phase (recon decision: deferred).
 */
class StoreRoomLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route gate has already required this; restated so the request
        // cannot be reused on a route that forgot it.
        return (bool) $this->user()?->can('manage', RoomLayout::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'width' => ['required', 'integer', 'min:1', 'max:100000'],
            'height' => ['required', 'integer', 'min:1', 'max:100000'],
            'grid_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'background_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
