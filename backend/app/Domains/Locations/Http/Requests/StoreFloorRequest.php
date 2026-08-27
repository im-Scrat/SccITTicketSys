<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates floor creation within a building (SRS FR-LOC-002).
 *
 * `(building_id, floor_number)` uniqueness is checked here so the administrator
 * gets a field-level 422 ("Floor 3 already exists in this building") instead of a
 * raw constraint violation; the database unique constraint remains the backstop.
 * Archived floors are included in the check — the number is still taken until the
 * floor is restored or purged.
 *
 * Negative numbers are allowed on purpose: basements are floors too.
 */
class StoreFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Floor::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $building = $this->route('building');

        return [
            'floor_number' => [
                'required',
                'integer',
                'min:-10',
                'max:200',
                Rule::unique('floors', 'floor_number')->where(
                    fn ($query) => $query->where(
                        'building_id',
                        $building instanceof Building ? $building->getKey() : null,
                    ),
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'floor_number.unique' => 'That floor number already exists in this building.',
        ];
    }
}
