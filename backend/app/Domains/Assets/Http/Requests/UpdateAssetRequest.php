<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Requests;

use App\Domains\Assets\Rules\AssetCreationRules;
use App\Enums\PcCondition;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an asset edit (SRS FR-AST-002).
 *
 * Uniqueness rules `ignore()` the record being edited, so re-saving an unchanged
 * tag is not a conflict with itself.
 *
 * `status` and `room` are **absent by design**: a lifecycle change goes through
 * `PUT /status` and a move through `POST /transfer`, because each writes a
 * history row alongside the change. Accepting them here would let an edit
 * silently bypass `asset_status_history` / `asset_transfers` and break the
 * guarantee that history is complete (FR-AST-005/006).
 */
class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset && (bool) $this->user()?->can('update', $asset);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $asset = $this->route('asset');
        $id = $asset instanceof Asset ? $asset->getKey() : null;

        // The form offers Working / For repair (DD-114); an asset recorded
        // earlier as faulty or decommissioned may be re-saved unchanged.
        $conditions = PcCondition::assetFormValues();
        if ($asset instanceof Asset) {
            $conditions[] = $asset->condition->value;
        }

        $other = array_map(
            static fn (array $rules): array => ['sometimes', ...$rules],
            AssetCreationRules::otherRules(),
        );

        return [
            'asset_tag' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('assets', 'asset_tag')->ignore($id)],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Optional like on create: null files the asset under "Unspecified
            // model", and "Other" (a described model) is filed in the catalog.
            'hardware_model' => ['sometimes', 'nullable', 'integer', Rule::exists('hardware_models', 'id')->whereNull('deleted_at')],
            'hardware_model_other' => ['sometimes', 'nullable', 'string', 'max:255', 'prohibits:hardware_model'],
            'supplier' => ['sometimes', 'nullable', 'string', Rule::exists('suppliers', 'name')->whereNull('deleted_at')],

            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('assets', 'serial_number')->ignore($id)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255'],

            'condition' => ['sometimes', 'required', 'string', Rule::in(array_values(array_unique($conditions)))],

            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'purchase_date' => ['sometimes', 'nullable', 'date'],
            'warranty_expiration' => ['sometimes', 'nullable', 'date'],

            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...$other,
        ];
    }

    /**
     * Warranty must not expire before purchase — checked here rather than with
     * `after_or_equal:purchase_date`, because on a **partial** update either date
     * may be absent from the payload while still being set on the record. The
     * rule string would then compare against a missing field and quietly pass,
     * letting an edit slip past into the `assets_warranty_check` CHECK and
     * surface as a 500 instead of a field error.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $asset = $this->route('asset');

                $purchase = $this->effectiveDate('purchase_date', $asset instanceof Asset ? $asset->purchase_date : null);
                $warranty = $this->effectiveDate('warranty_expiration', $asset instanceof Asset ? $asset->warranty_expiration : null);

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
     * The value a field will hold after this update: the submitted one when the
     * key is present (including an explicit null), otherwise the stored one.
     */
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asset_tag.unique' => 'That asset tag is already in use.',
            'serial_number.unique' => 'That serial number is already recorded against another asset.',
            'hardware_model_other.prohibits' => 'Choose a catalog model or describe another one, not both.',
            ...AssetCreationRules::otherMessages(),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('asset_tag'))) {
            $this->merge(['asset_tag' => trim($this->string('asset_tag')->upper()->value())]);
        }

        if (is_string($this->input('hardware_model_other'))) {
            $other = trim($this->string('hardware_model_other')->value());
            $this->merge(['hardware_model_other' => $other === '' ? null : $other]);
        }
    }
}
