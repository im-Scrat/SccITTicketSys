<?php

declare(strict_types=1);

namespace App\Domains\Assets\Rules;

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use Illuminate\Validation\Rule;

/**
 * The rules for registering a PC unit, in one place (Phase 2.10 R6, SDD DD-96).
 *
 * Extracted verbatim from `StorePcUnitRequest`, which now delegates here, so a
 * PC unit imported from a spreadsheet passes exactly the checks a PC unit
 * created in the form does — a parity test pins that. Two creation paths with
 * two rule sets would drift, and the import is the one nobody would notice.
 *
 * {@see normalize()} is the request's `prepareForValidation()`, for the same
 * reason: an identifier upper-cased in one path and not the other is a
 * duplicate that the database would accept.
 *
 * `specification` keys are derived from {@see UpsertPcSpecification::FIELDS},
 * so the rules cannot drift from the columns that actually exist. `ip_address`
 * maps to a Postgres `inet` column — an invalid address would be a cast error,
 * so it is validated as an IP to produce a field message instead.
 */
final class PcUnitCreationRules
{
    /** A MAC address written with colons or hyphens. */
    public const MAC_PATTERN = '/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/';

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
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
            'mac_address' => ['nullable', 'string', 'max:255', 'regex:'.self::MAC_PATTERN],

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
    public static function messages(): array
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

    /**
     * Identifiers are stored trimmed and upper-cased.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        foreach (['unit_code', 'asset_tag'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = trim(mb_strtoupper($data[$key]));
            }
        }

        return $data;
    }
}
