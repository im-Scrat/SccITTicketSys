<?php

declare(strict_types=1);

namespace App\Domains\Assets\Rules;

use App\Enums\AssetStatus;
use App\Enums\PcCondition;
use Illuminate\Validation\Rule;

/**
 * The rules for registering an asset, in one place (Phase 2.10 R6, SDD DD-96).
 *
 * Extracted verbatim from `StoreAssetRequest`, which now delegates here, so an
 * imported asset passes exactly the checks the form applies (a parity test
 * pins it). The rules mirror the database constraints rather than replacing
 * them: `assets_warranty_check` and `assets_purchase_price_check` remain the
 * last line of defense (SDD DD-08).
 *
 * Rooms and technicians are addressed by uuid; the catalog model by id, which
 * only ever travels inside a payload and never in a URL.
 */
final class AssetCreationRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'asset_tag' => ['required', 'string', 'max:255', Rule::unique('assets', 'asset_tag')],
            'name' => ['nullable', 'string', 'max:255'],
            'hardware_model' => ['required', 'integer', Rule::exists('hardware_models', 'id')->whereNull('deleted_at')],
            'supplier' => ['nullable', 'string', Rule::exists('suppliers', 'name')->whereNull('deleted_at')],
            'room' => ['nullable', 'string', 'uuid', Rule::exists('rooms', 'uuid')->whereNull('deleted_at')],
            'technician' => ['nullable', 'string', 'uuid', Rule::exists('users', 'uuid')->whereNull('deleted_at')],

            'serial_number' => ['nullable', 'string', 'max:255', Rule::unique('assets', 'serial_number')],
            'barcode' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', 'string', Rule::in(AssetStatus::values())],
            'condition' => ['nullable', 'string', Rule::in(PcCondition::values())],

            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expiration' => ['nullable', 'date', 'after_or_equal:purchase_date'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'asset_tag.unique' => 'That asset tag is already in use.',
            'serial_number.unique' => 'That serial number is already recorded against another asset.',
            'hardware_model.exists' => 'Choose an available catalog model for this asset.',
            'room.exists' => 'Choose an available room for this asset.',
            'technician.exists' => 'Choose an available staff member.',
            'warranty_expiration.after_or_equal' => 'Warranty cannot expire before the purchase date.',
        ];
    }

    /**
     * The asset tag is stored trimmed and upper-cased.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (is_string($data['asset_tag'] ?? null)) {
            $data['asset_tag'] = trim(mb_strtoupper($data['asset_tag']));
        }

        return $data;
    }
}
