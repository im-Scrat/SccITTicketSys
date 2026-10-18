<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\Actions\UpsertPcSpecification;
use App\Domains\Assets\DTOs\ImportIssue;
use App\Domains\Assets\Rules\AssetCreationRules;
use App\Domains\Assets\Rules\PcUnitCreationRules;
use App\Enums\ImportEntityType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Checks a built row against the **same** rules the create forms use (Phase
 * 2.10 R6, SDD DD-96).
 *
 * Two kinds of rule are left out at preview time, because the import answers
 * them more precisely itself: uniqueness (the duplicate detector compares
 * case-insensitively and includes archived records, where the form's rule
 * does neither), and the references — room, catalog model, supplier, custodian
 * — which the location matcher, the catalog resolver and the reference
 * resolver decide with the administrator. At save time the committer runs the
 * full rules, uniqueness and references included, so nothing reaches the
 * register that the form would have refused.
 */
final class ImportRowValidator
{
    private const REFERENCES = ['room', 'hardware_model', 'supplier', 'technician'];

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ImportIssue>
     */
    public function validate(ImportEntityType $type, array $payload, bool $full = false): array
    {
        $rules = $type === ImportEntityType::PcUnit ? PcUnitCreationRules::rules() : AssetCreationRules::rules();
        $messages = $type === ImportEntityType::PcUnit ? PcUnitCreationRules::messages() : AssetCreationRules::messages();

        if (! $full) {
            $rules = $this->previewRules($rules);
        }

        $validator = Validator::make($payload, $rules, $messages, $this->attributes($type));
        if (! $validator->fails()) {
            return [];
        }

        $issues = [];
        $failed = $validator->failed();
        foreach ($validator->errors()->messages() as $field => $fieldMessages) {
            // The validator names failed rules in StudlyCase ("AfterOrEqual").
            $rule = Str::snake((string) array_key_first($failed[$field] ?? []));
            $code = match ($rule) {
                'required' => 'required_missing',
                'max' => 'too_long',
                'after_or_equal' => 'warranty_before_purchase',
                'date' => 'date_unparseable',
                default => 'invalid_format',
            };
            $issues[] = ImportIssue::make($code, $this->target($field), (string) $fieldMessages[0], ['rule' => $rule]);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function previewRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $list) {
            if (in_array($key, self::REFERENCES, true)) {
                continue;
            }
            $out[$key] = array_values(array_filter((array) $list, fn (mixed $rule): bool => ! $rule instanceof Unique));
        }

        // An asset's catalog model is the resolver's to decide, not a missing field.
        unset($out['hardware_model']);

        return $out;
    }

    /**
     * Field names as the administrator knows them.
     *
     * @return array<string, string>
     */
    private function attributes(ImportEntityType $type): array
    {
        $attributes = [];
        foreach (ImportFieldDictionary::keys() as $target) {
            $key = ImportFieldDictionary::payloadKey($target, $type);
            if ($key !== null) {
                $attributes[$key] = ImportFieldDictionary::label($target);
            }
        }
        foreach (UpsertPcSpecification::FIELDS as $field) {
            $attributes["specification.{$field}"] ??= str_replace('_', ' ', $field);
        }

        return $attributes;
    }

    /** The import target a payload key came from, for the issue's field. */
    private function target(string $payloadKey): string
    {
        foreach (ImportFieldDictionary::keys() as $target) {
            foreach ([ImportEntityType::PcUnit, ImportEntityType::Asset] as $type) {
                if (ImportFieldDictionary::payloadKey($target, $type) === $payloadKey) {
                    return $target;
                }
            }
        }

        return $payloadKey;
    }
}
