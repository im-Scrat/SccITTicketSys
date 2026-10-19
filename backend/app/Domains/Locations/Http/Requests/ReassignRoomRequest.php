<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates moving a room's occupants elsewhere (SRS FR-LOC-004). The
 * destination must be a live, selectable room and cannot be the source room.
 */
class ReassignRoomRequest extends FormRequest
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
        return [
            'to_room' => [
                'required',
                'string',
                'uuid',
                Rule::exists('rooms', 'uuid')->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $room = $this->route('room');
            $target = $this->input('to_room');

            if ($room instanceof Room && is_string($target) && $room->uuid === $target) {
                $validator->errors()->add('to_room', 'Choose a different room to move the occupants to.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_room.exists' => 'Choose an available room to move the occupants to.',
        ];
    }
}
