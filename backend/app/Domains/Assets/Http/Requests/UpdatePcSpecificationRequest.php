<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the PC specification editor (SRS FR-PC-003).
 *
 * The accepted keys are generated from {@see UpsertPcSpecification::FIELDS},
 * which is also what the Action writes and what `PcSpecificationResource` reads —
 * one list, three uses, so a new column cannot be half-wired.
 *
 * Every field is a free-text string on purpose: specifications are transcribed
 * from whatever the hardware reports ("16 GB DDR4-3200", "512 GB NVMe SSD"), and
 * forcing them into structured units would make honest data impossible to enter.
 */
class UpdatePcSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pcUnit = $this->route('pc_unit');

        return $pcUnit instanceof PcUnit && (bool) $this->user()?->can('updateSpecification', $pcUnit);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (UpsertPcSpecification::FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
