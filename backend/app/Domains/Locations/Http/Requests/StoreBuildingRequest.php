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
 *
 * `code` is optional (owner request 2026-10-19: the name is enough). When it is
 * left out, CreateBuilding derives a unique one from the name, so every
 * building still has the code that labels, reports and the asset import match
 * on — the column stays NOT NULL and unique, with no migration.
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
            'code' => ['nullable', 'string', 'max:50', Rule::unique('buildings', 'code')],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $code = trim($this->string('code')->upper()->value());
            $this->merge(['code' => $code === '' ? null : $code]);
        }
    }
}
