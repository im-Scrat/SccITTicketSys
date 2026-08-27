<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates room creation (SRS FR-LOC-003). The target floor is addressed by
 * uuid in the payload (`floor`), never by numeric id. `capacity >= 0` mirrors the
 * `rooms_capacity_check` database constraint.
 */
class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Room::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'floor' => ['required', 'string', 'uuid', Rule::exists('floors', 'uuid')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('rooms', 'code')],
            'room_number' => ['nullable', 'string', 'max:50'],
            'room_type' => ['required', 'string', Rule::in(RoomType::values())],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'floor.exists' => 'Choose an available floor for this room.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->string('code')->upper()->value())]);
        }
    }
}
