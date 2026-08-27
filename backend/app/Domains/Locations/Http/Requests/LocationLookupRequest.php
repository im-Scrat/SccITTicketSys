<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the narrow location lookup (SRS FR-LOC-011).
 *
 * Authorized by `selectLocation` — the ability granted by the *workflow* that
 * needs a location field, never by a `locations.*` permission. An account with no
 * such workflow (and no location permission) is refused outright.
 */
class LocationLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('selectLocation', Room::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'uuid'],
            'floor' => ['nullable', 'string', 'uuid'],
            'room_type' => ['nullable', 'string', Rule::in([...RoomType::values(), 'all'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
