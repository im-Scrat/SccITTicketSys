<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\PcUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a PC-unit edit (SRS FR-PC-001/002/005).
 *
 * Uniqueness rules `ignore()` the record being edited. The warranty check is an
 * after-hook rather than `after_or_equal:purchase_date` for the same reason as
 * {@see UpdateAssetRequest}: on a partial update either date may live on the
 * record rather than in the payload, and the rule string cannot see that.
 *
 * The specification is edited through its own endpoint (`PUT
 * /pc-units/{uuid}/specification`), so it is not accepted here — one write path
 * per audited concern.
 */
class UpdatePcUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pcUnit = $this->route('pc_unit');

        return $pcUnit instanceof PcUnit && (bool) $this->user()?->can('update', $pcUnit);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pcUnit = $this->route('pc_unit');
        $id = $pcUnit instanceof PcUnit ? $pcUnit->getKey() : null;

        return [
            'unit_code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('pc_units', 'unit_code')->ignore($id)],
            'pc_name' => ['sometimes', 'required', 'string', 'max:255'],
            'asset_tag' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('pc_units', 'asset_tag')->ignore($id)],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('pc_units', 'hostname')->ignore($id)],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],

            'room' => ['sometimes', 'nullable', 'string', 'uuid', Rule::exists('rooms', 'uuid')->whereNull('deleted_at')],

            'ip_address' => ['sometimes', 'nullable', 'string', 'ip'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],

            'status' => ['sometimes', 'required', 'string', Rule::in(PcStatus::values())],
            'current_condition' => ['sometimes', 'required', 'string', Rule::in(PcCondition::values())],

            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'warranty_expiration' => ['sometimes', 'nullable', 'date'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $pcUnit = $this->route('pc_unit');

                $purchase = $this->effectiveDate('purchase_date', $pcUnit instanceof PcUnit ? $pcUnit->purchase_date : null);
                $warranty = $this->effectiveDate('warranty_expiration', $pcUnit instanceof PcUnit ? $pcUnit->warranty_expiration : null);

                if ($purchase !== null && $warranty !== null && $warranty < $purchase) {
                    $validator->errors()->add(
                        'warranty_expiration',
                        'Warranty cannot expire before the purchase date.',
                    );
                }
            },
        ];
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

    /** The value a field will hold after this update. */
    private function effectiveDate(string $field, mixed $stored): ?string
    {
        if (! $this->has($field)) {
            return $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d') : null;
        }

        $value = $this->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
