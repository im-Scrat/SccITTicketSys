<?php

declare(strict_types=1);

namespace App\Domains\Locations\Http\Requests;

use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates building creation (SRS FR-LOC-001). `code` is the human-facing
 * identifier and must be unique across archived rows too — reusing the code of
 * an archived building would make the audit trail ambiguous.
 */
class StoreBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Building::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('buildings', 'code')],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->string('code')->upper()->value())]);
        }
    }
}
