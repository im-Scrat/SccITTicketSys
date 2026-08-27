<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates room updates (SRS FR-LOC-003). `floor` is optional here: omit it to
 * leave the room where it is, or pass another floor's uuid to move it.
 */
class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room && (bool) $this->user()?->can('update', $room);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'floor' => ['nullable', 'string', 'uuid', Rule::exists('floors', 'uuid')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rooms', 'code')->ignore($room instanceof Room ? $room->getKey() : null),
            ],
            'room_number' => ['nullable', 'string', 'max:50'],
            'room_type' => ['required', 'string', Rule::in(RoomType::values())],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:2000'],
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
