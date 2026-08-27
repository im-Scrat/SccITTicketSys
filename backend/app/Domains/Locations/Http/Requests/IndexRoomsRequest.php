<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates room directory list parameters (SRS FR-LOC-003). Building and floor
 * scopes are uuids (never numeric ids); `sort` is allow-listed to match
 * LocationDirectoryQuery.
 */
class IndexRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Room::class);
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
            'active' => ['nullable', 'string', Rule::in(['all', 'active', 'inactive', '1', '0', 'true', 'false'])],
            'trashed' => ['nullable', 'string', Rule::in(['without', 'with', 'only'])],
            'sort' => ['nullable', 'string', Rule::in([
                'name', 'code', 'room_type', 'capacity', 'building', 'floor_number', 'pc_units_count', 'created_at',
            ])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
