<?php

declare(strict_types=1);

namespace App\Domains\FloorPlan\Http\Requests;

use App\Domains\FloorPlan\Services\FloorPlanDefaults;
use App\Enums\FloorPlanFixtureKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a whole-layout save (Phase 2.11, SDD DD-101).
 *
 * Only the *shape* is checked here. Everything that needs the room — that each
 * machine belongs to it, that every shape fits inside it, that no two
 * workstations overlap — is `SaveRoomLayout`'s job, after authorization, so an
 * unauthorized caller learns nothing about the room from a validation message.
 *
 * `authorize()` is left to the route gate and the action (the same policy
 * ability twice); returning true here is not a bypass.
 */
class SaveRoomLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],

            'settings' => ['required', 'array'],
            'settings.width' => ['required', 'integer', 'min:1', 'max:100000'],
            'settings.height' => ['required', 'integer', 'min:1', 'max:100000'],
            'settings.grid_size' => ['required', 'integer', 'min:1', 'max:1000'],

            'workstations' => ['present', 'array', 'max:1000'],
            'workstations.*.pc_unit' => ['required', 'string', 'uuid', 'distinct'],
            'workstations.*.x' => ['required', 'numeric', 'min:0', 'max:100000'],
            'workstations.*.y' => ['required', 'numeric', 'min:0', 'max:100000'],
            'workstations.*.rotation' => ['required', 'integer', Rule::in([0, 90, 180, 270])],

            'fixtures' => ['present', 'array', 'max:'.FloorPlanDefaults::MAX_FIXTURES],
            'fixtures.*.id' => ['required', 'string', 'uuid', 'distinct'],
            'fixtures.*.kind' => ['required', 'string', Rule::in(FloorPlanFixtureKind::values())],
            'fixtures.*.x' => ['required', 'numeric', 'min:0', 'max:100000'],
            'fixtures.*.y' => ['required', 'numeric', 'min:0', 'max:100000'],
            'fixtures.*.width' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'fixtures.*.height' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'fixtures.*.rotation' => ['required', 'numeric', 'min:0', 'lt:360'],
            // TrimStrings + ConvertEmptyStringsToNull turn a blank label into null.
            'fixtures.*.label' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workstations.*.pc_unit.distinct' => 'A PC unit can be placed only once on a layout.',
            'workstations.*.rotation.in' => 'A workstation turns in quarter steps: 0, 90, 180 or 270 degrees.',
            'fixtures.max' => 'A layout can hold at most '.FloorPlanDefaults::MAX_FIXTURES.' fixtures.',
        ];
    }
}
