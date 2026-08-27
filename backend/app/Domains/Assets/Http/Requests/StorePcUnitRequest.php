<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $rules = [
            'unit_code' => ['required', 'string', 'max:255', Rule::unique('pc_units', 'unit_code')],
            'pc_name' => ['required', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('pc_units', 'asset_tag')],
            'hostname' => ['nullable', 'string', 'max:255', Rule::unique('pc_units', 'hostname')],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],

            'room' => ['nullable', 'string', 'uuid', Rule::exists('rooms', 'uuid')->whereNull('deleted_at')],

            'ip_address' => ['nullable', 'string', 'ip'],
            'mac_address' => ['nullable', 'string', 'max:255', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],

            'status' => ['nullable', 'string', Rule::in(PcStatus::values())],
            'current_condition' => ['nullable', 'string', Rule::in(PcCondition::values())],

            'purchase_date' => ['nullable', 'date'],
            'warranty_expiration' => ['nullable', 'date', 'after_or_equal:purchase_date'],

            'notes' => ['nullable', 'string', 'max:5000'],
            'specification' => ['nullable', 'array'],
        ];

        foreach (UpsertPcSpecification::FIELDS as $field) {
            $rules["specification.{$field}"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_code.unique' => 'That unit code is already in use.',
            'asset_tag.unique' => 'That asset tag is already in use.',
            'hostname.unique' => 'That hostname is already in use.',
            'mac_address.regex' => 'Enter a MAC address like 00:1B:44:11:3A:B7.',
            'room.exists' => 'Choose an available room for this PC.',
            'warranty_expiration.after_or_equal' => 'Warranty cannot expire before the purchase date.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('unit_code'))) {
            $this->merge(['unit_code' => trim($this->string('unit_code')->upper()->value())]);
        }

        if (is_string($this->input('asset_tag'))) {
            $this->merge(['asset_tag' => trim($this->string('asset_tag')->upper()->value())]);
        }
    }
}
