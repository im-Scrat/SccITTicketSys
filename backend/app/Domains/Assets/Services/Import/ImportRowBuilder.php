<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\DTOs\BuiltImportRow;
use App\Domains\Assets\DTOs\ImportIssue;
use App\Domains\Assets\Rules\AssetCreationRules;
use App\Domains\Assets\Rules\PcUnitCreationRules;
use App\Enums\AssetImportEntityMode;
use App\Enums\AssetStatus;
use App\Enums\ComponentType;
use App\Enums\ImportEntityType;
use App\Enums\PcStatus;
use App\Models\AssetImport;
use App\Models\AssetImportRow;

/**
 * Turns one row's cells, the confirmed mapping and the administrator's
 * corrections into the payload that would be saved (Phase 2.10 R6, SDD DD-96).
 *
 * The order of precedence for every value is: a **correction** the
 * administrator typed, then the **file** (through its value meaning, for a
 * status or category), then a **default** for an empty cell. Each payload key
 * records which one it came from, because "values from your file" in the
 * post-import review must mean exactly that.
 *
 * Identifiers are normalised by the same function the create forms use, so a
 * spreadsheet's "pc-01" and the form's "PC-01" are the same unit code.
 */
final class ImportRowBuilder
{
    private const IDENTIFIERS = ['unit_code', 'asset_tag', 'serial_number', 'hostname', 'barcode'];

    private const DATES = ['purchase_date', 'warranty_expiration'];

    public function build(AssetImport $import, AssetImportRow $row, ?string $sheetName = null): BuiltImportRow
    {
        $mode = $import->entity_mode;
        $cells = $row->raw ?? [];
        $flags = $row->cell_flags ?? [];
        $corrections = $row->corrections ?? [];
        $defaults = $import->defaults ?? [];
        $issues = [];

        // target => [value, source, flags]
        $values = [];
        foreach ($import->mapping ?? [] as $index => $target) {
            if ($target === '') {
                continue;
            }
            $value = trim((string) ($cells[(string) $index] ?? ''));
            if ($value !== '') {
                $values[$target] = [$value, 'file', $flags[(string) $index] ?? []];
            } elseif (in_array('formula_without_value', $flags[(string) $index] ?? [], true)) {
                $issues[] = ImportIssue::make('formula_without_value', $target, ImportFieldDictionary::label($target).' is a formula with no saved result, so it was left empty. Open the file in Excel, save it, and upload it again to keep it.');
            } elseif (in_array('cell_error', $flags[(string) $index] ?? [], true)) {
                $issues[] = ImportIssue::make('cell_error', $target, ImportFieldDictionary::label($target).' holds a spreadsheet error (such as #N/A), so it was left empty.');
            }
        }
        foreach ($corrections as $target => $correction) {
            if ($target === 'room') {
                continue;
            }
            $value = is_scalar($correction['value'] ?? null) ? trim((string) $correction['value']) : '';
            $values[$target] = $value === '' ? null : [$value, 'correction', []];
        }
        $values = array_filter($values);

        // Which register.
        $type = match ($mode) {
            AssetImportEntityMode::PcUnits => ImportEntityType::PcUnit,
            AssetImportEntityMode::Assets => ImportEntityType::Asset,
            AssetImportEntityMode::Mixed => null,
        };
        $category = null;
        $categorySource = null;
        if ($mode !== AssetImportEntityMode::PcUnits) {
            [$category, $categorySource] = $this->meaning($import, 'category', $values['category'] ?? null, $defaults['category'] ?? null, $issues);
            if ($mode === AssetImportEntityMode::Mixed) {
                $type = match (true) {
                    $category === ImportValueMapper::PC_UNIT => ImportEntityType::PcUnit,
                    is_string($category) && str_starts_with($category, 'asset:') => ImportEntityType::Asset,
                    default => null,
                };
                if ($type === null && ! $this->hasIssue($issues, 'unknown_value')) {
                    $issues[] = ImportIssue::make('type_unmapped', 'category', 'This row has no category, so it is not clear whether it is a PC unit or other equipment. Enter its category.');
                }
            }
        }

        if ($type === null) {
            return new BuiltImportRow(null, [], [], $this->location($values, $sheetName, $defaults), null, null, null, null, $issues);
        }

        $payload = [];
        $sources = [];
        $put = function (string $key, mixed $value, string $source) use (&$payload, &$sources): void {
            if (str_starts_with($key, 'specification.')) {
                $payload['specification'][substr($key, 14)] = $value;
            } else {
                $payload[$key] = $value;
            }
            $sources[$key] = $source;
        };

        foreach ($values as $target => [$value, $source, $cellFlags]) {
            $key = ImportFieldDictionary::payloadKey($target, $type);
            if ($key === null || str_starts_with($key, 'location.') || str_starts_with($key, 'catalog.') || in_array($target, ['status', 'condition', 'category'], true)) {
                continue;
            }

            if (in_array($target, self::DATES, true)) {
                $parsed = ImportDateParser::parse($value, $cellFlags, $import->date_order);
                if ($parsed['issue'] !== null) {
                    $issues[] = ImportIssue::make($parsed['issue'], $target, ImportFieldDictionary::label($target).': '.$parsed['message']);
                }
                if ($parsed['value'] !== null) {
                    $put($key, $parsed['value'], $source);
                }

                continue;
            }

            if ($target === 'purchase_price') {
                [$number, $issue] = $this->price($value);
                if ($issue !== null) {
                    $issues[] = ImportIssue::make($issue, $target, $issue === 'number_ambiguous'
                        ? "Purchase price {$value} could mean two different amounts (a comma can separate thousands or decimals). Enter it like 1234.50."
                        : "Purchase price {$value} is not a number.");
                } else {
                    $put($key, $number, $source);
                }

                continue;
            }

            if ($target === 'mac_address') {
                $mac = $this->mac($value);
                if ($mac !== null && $mac !== $value) {
                    $issues[] = ImportIssue::make('mac_reformatted', $target, "MAC address {$value} will be saved as {$mac}.");
                }
                $put($key, $mac ?? $value, $source);

                continue;
            }

            if (in_array($target, self::IDENTIFIERS, true) && in_array('number', $cellFlags, true)) {
                $issues[] = ImportIssue::make('number_as_identifier', $target, ImportFieldDictionary::label($target)." {$value} was stored as a number in the spreadsheet; any leading zeros were lost before the import saw it.");
            }
            if ($source === 'file' && preg_match('/^[=+\-@]/', $value)) {
                $issues[] = ImportIssue::make('formula_like_text', $target, ImportFieldDictionary::label($target).' starts like a spreadsheet formula. It is saved as plain text and never calculated.');
            }
            if (in_array('merged_fill', $cellFlags, true)) {
                $issues[] = ImportIssue::make('merged_fill', $target, ImportFieldDictionary::label($target).' came from a merged cell that covers several rows.');
            }

            $put($key, $value, $source);
        }

        // Status and condition, through their value meanings.
        $statusField = $type === ImportEntityType::PcUnit ? 'status.pc_unit' : 'status.asset';
        $statusDefault = $type === ImportEntityType::PcUnit ? ($defaults['pc_status'] ?? null) : ($defaults['asset_status'] ?? null);
        [$status, $statusSource] = $this->meaning($import, $statusField, $values['status'] ?? null, $statusDefault, $issues);
        if ($status !== null) {
            $put('status', $status, (string) $statusSource);
            $terminal = $type === ImportEntityType::PcUnit
                ? $status === PcStatus::Retired->value
                : in_array($status, [AssetStatus::Retired->value, AssetStatus::Disposed->value], true);
            if ($terminal) {
                $issues[] = ImportIssue::make('terminal_status', 'status', 'This record will be created as '.($type === ImportEntityType::PcUnit ? PcStatus::from($status)->label() : AssetStatus::from($status)->label()).'.');
            }
        }
        [$condition, $conditionSource] = $this->meaning($import, 'condition', $values['condition'] ?? null, $defaults['condition'] ?? null, $issues);
        if ($condition !== null) {
            $put($type === ImportEntityType::PcUnit ? 'current_condition' : 'condition', $condition, (string) $conditionSource);
        }

        // Required values.
        foreach (ImportFieldDictionary::required($type) as $target) {
            $present = $target === 'category' ? $category !== null : isset($values[$target]);
            if (! $present) {
                $issues[] = ImportIssue::make($target === 'category' ? 'catalog_type_missing' : 'required_missing', $target,
                    ImportFieldDictionary::label($target).' is required for '.($type === ImportEntityType::PcUnit ? 'a PC unit' : 'other equipment').'.');
            }
        }

        $catalog = null;
        if ($type === ImportEntityType::Asset) {
            $componentType = is_string($category) && str_starts_with($category, 'asset:') ? substr($category, 6) : null;
            $catalog = [
                'component_type' => $componentType !== null && ComponentType::tryFrom($componentType) !== null ? $componentType : null,
                'manufacturer' => $values['brand'][0] ?? null,
                'model' => $values['model'][0] ?? null,
                'model_number' => $values['model_number'][0] ?? null,
            ];
            foreach (['brand' => 'catalog.manufacturer', 'model' => 'catalog.model', 'model_number' => 'catalog.model_number'] as $target => $key) {
                if (isset($values[$target])) {
                    $sources[$key] = $values[$target][1];
                }
            }
            if ($categorySource !== null) {
                $sources['catalog.component_type'] = $categorySource;
            }
        }

        $payload = $type === ImportEntityType::PcUnit ? PcUnitCreationRules::normalize($payload) : AssetCreationRules::normalize($payload);

        $roomOverride = null;
        $location = $this->location($values, $sheetName, $defaults);
        if (isset($corrections['room'])) {
            $roomOverride = is_string($corrections['room']['value'] ?? null) ? $corrections['room']['value'] : '';
            $sources['room'] = 'correction';
        } elseif (array_intersect_key($values, array_flip(['building', 'floor', 'room', 'room_code', 'location'])) !== []) {
            $sources['room'] = 'file';
        } elseif (array_filter($location) !== []) {
            // Only the sheet's name: a default, not a value from the file's cells.
            $sources['room'] = 'default';
        }

        return new BuiltImportRow(
            $type,
            $payload,
            $sources,
            $location,
            $catalog,
            $roomOverride,
            $type === ImportEntityType::Asset ? ($values['supplier'][0] ?? null) : null,
            $type === ImportEntityType::Asset ? ($values['custodian_email'][0] ?? null) : null,
            $issues,
        );
    }

    /**
     * A status, condition or category: the corrected meaning, the file value's
     * chosen meaning, or the default.
     *
     * @param  array{0: string, 1: string, 2: list<string>}|null  $value
     * @param  list<ImportIssue>  $issues
     * @return array{0: string|null, 1: string|null} meaning, source
     */
    private function meaning(AssetImport $import, string $field, ?array $value, mixed $default, array &$issues): array
    {
        $target = explode('.', $field)[0];

        if ($value !== null) {
            [$raw, $source] = $value;
            if ($source === 'correction') {
                if (ImportValueMapper::isValid($field, $raw, $import->entity_mode)) {
                    return [$raw, 'correction'];
                }
                $issues[] = ImportIssue::make('unknown_value', $target, ImportFieldDictionary::label($target)." \"{$raw}\" is not one the app knows.");

                return [null, null];
            }

            $meaning = $import->value_mappings[$field][ImportFieldDictionary::normalize($raw)] ?? null;
            if (is_string($meaning) && ImportValueMapper::isValid($field, $meaning, $import->entity_mode)) {
                return [$meaning, 'mapping'];
            }
            $issues[] = ImportIssue::make('unknown_value', $target, ImportFieldDictionary::label($target)." \"{$raw}\" is not one the app knows. Choose what it means in Map columns, or correct it here.");

            return [null, null];
        }

        if (is_string($default) && $default !== '' && ImportValueMapper::isValid($field, $default, $import->entity_mode)) {
            return [$default, 'default'];
        }

        return [null, null];
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: list<string>}>  $values
     * @param  array<string, mixed>  $defaults
     * @return array{building: string|null, floor: string|null, room: string|null, room_code: string|null, location: string|null}
     */
    private function location(array $values, ?string $sheetName, array $defaults): array
    {
        $parts = [];
        foreach (['building', 'floor', 'room', 'room_code', 'location'] as $part) {
            $parts[$part] = $values[$part][0] ?? null;
        }

        if (array_filter($parts) === [] && ! empty($defaults['location_from_sheet_name']) && $sheetName !== null && trim($sheetName) !== '') {
            $parts['location'] = trim($sheetName);
        }

        return $parts;
    }

    /**
     * @return array{0: string|null, 1: string|null} number, issue code
     */
    private function price(string $value): array
    {
        $clean = (string) preg_replace('/[^\d.,\-]/u', '', $value);
        if ($clean === '' || ! preg_match('/\d/', $clean)) {
            return [null, 'invalid_format'];
        }

        $hasComma = str_contains($clean, ',');
        $hasDot = str_contains($clean, '.');
        if ($hasComma && $hasDot) {
            // The later separator is the decimal one.
            $clean = strrpos($clean, ',') > strrpos($clean, '.')
                ? str_replace(['.', ','], ['', '.'], $clean)
                : str_replace(',', '', $clean);
        } elseif ($hasComma) {
            if (preg_match('/^-?\d{1,3}(,\d{3})+$/', $clean)) {
                $clean = str_replace(',', '', $clean);
            } else {
                return [null, 'number_ambiguous'];
            }
        }

        return is_numeric($clean) ? [$clean, null] : [null, 'invalid_format'];
    }

    /** A MAC address in any common notation, as 00:1B:44:11:3A:B7. */
    private function mac(string $value): ?string
    {
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $value);
        if (strlen($hex) !== 12 || ! preg_match('/^[0-9A-Fa-f:\-.\s]+$/', $value)) {
            return null;
        }

        return strtoupper(implode(':', str_split($hex, 2)));
    }

    /**
     * @param  list<ImportIssue>  $issues
     */
    private function hasIssue(array $issues, string $code): bool
    {
        foreach ($issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }
}
