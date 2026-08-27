<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates building updates (SRS FR-LOC-001). The uniqueness rule ignores the
 * record being edited so saving an unchanged code is not a false conflict.
 */
class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return $building instanceof Building && (bool) $this->user()?->can('update', $building);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $building = $this->route('building');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('buildings', 'code')->ignore($building instanceof Building ? $building->getKey() : null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->string('code')->upper()->value())]);
        }
    }
}
