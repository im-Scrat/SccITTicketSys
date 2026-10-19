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
 *
 * Every field is optional (owner request 2026-10-19). The two the database
 * still requires are filled in by CreateAsset rather than asked for:
 *
 *  - `asset_tag` (NOT NULL, unique) — generated as AST-000123 when not given.
 *    It is no longer shown anywhere; it survives as the stable internal
 *    identifier the import's duplicate check and the list's paging tie-break
 *    rely on. A register that does carry tags may still import them.
 *  - `hardware_model_id` (NOT NULL FK) — a model typed into "Other"
 *    (`hardware_model_other`) is filed in the catalog; no model at all files
 *    the asset under the shared "Unspecified model". Keeping the column NOT
 *    NULL keeps every join that derives an asset's category intact.
 */
final class AssetCreationRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'asset_tag' => ['nullable', 'string', 'max:255', Rule::unique('assets', 'asset_tag')],
            'name' => ['nullable', 'string', 'max:255'],
            'hardware_model' => ['nullable', 'integer', Rule::exists('hardware_models', 'id')->whereNull('deleted_at')],
            // "Other": a model not in the catalog yet, described in words.
            'hardware_model_other' => ['nullable', 'string', 'max:255', 'prohibits:hardware_model'],
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
            'hardware_model_other.prohibits' => 'Choose a catalog model or describe another one, not both.',
            'room.exists' => 'Choose an available room for this asset.',
            'technician.exists' => 'Choose an available staff member.',
            'warranty_expiration.after_or_equal' => 'Warranty cannot expire before the purchase date.',
        ];
    }

    /**
     * The asset tag is stored trimmed and upper-cased; a blank one means "not
     * given", so CreateAsset generates it. A blank "Other" model is no model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (is_string($data['asset_tag'] ?? null)) {
            $tag = trim(mb_strtoupper($data['asset_tag']));
            $data['asset_tag'] = $tag === '' ? null : $tag;
        }

        if (is_string($data['hardware_model_other'] ?? null)) {
            $other = trim($data['hardware_model_other']);
            $data['hardware_model_other'] = $other === '' ? null : $other;
        }

        return $data;
    }
}
