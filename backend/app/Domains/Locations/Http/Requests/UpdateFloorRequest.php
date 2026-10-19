<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Models\Floor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates floor updates (SRS FR-LOC-002). Renumbering is allowed — the public
 * identifier is the uuid — but must not collide with another floor in the same
 * building.
 */
class UpdateFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $floor = $this->route('floor');

        return $floor instanceof Floor && (bool) $this->user()?->can('update', $floor);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $floor = $this->route('floor');

        return [
            'floor_number' => [
                'required',
                'integer',
                'min:-10',
                'max:200',
                Rule::unique('floors', 'floor_number')
                    ->where(fn ($query) => $query->where(
                        'building_id',
                        $floor instanceof Floor ? $floor->building_id : null,
                    ))
                    ->ignore($floor instanceof Floor ? $floor->getKey() : null),
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
