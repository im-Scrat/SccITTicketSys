<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Domains\Assets\Rules\PcUnitCreationRules;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PC-unit creation (SRS FR-PC-001/002/005).
 *
 * The optional `specification` block is validated alongside the unit so a
 * machine can be registered complete in one request. Its keys are derived from
 * {@see UpsertPcSpecification::FIELDS}, so the rules cannot drift from the
 * columns that actually exist.
 *
 * `ip_address` maps to a Postgres `inet` column — an invalid address would be a
 * database-level cast error, so it is validated as an IP here to produce a field
 * message instead.
 *
 * The rules live in {@see PcUnitCreationRules} (Phase 2.10 R6), shared with the asset import.
 */
class StorePcUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', PcUnit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PcUnitCreationRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PcUnitCreationRules::messages();
    }

    protected function prepareForValidation(): void
    {
        // Only the identifiers the rules normalise; nothing else is rewritten.
        $this->merge(array_intersect_key(PcUnitCreationRules::normalize($this->all()), array_flip(['unit_code', 'asset_tag'])));
    }
}
