<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Actions\Concerns\GuardsAssetImport;
use App\Domains\Assets\Jobs\ValidateAssetImportJob;
use App\Domains\Assets\Services\Import\AssetImportValues;
use App\Domains\Assets\Services\Import\ImportColumnMapper;
use App\Domains\Assets\Services\Import\ImportValueMapper;
use App\Enums\AssetImportEntityMode;
use App\Enums\AssetImportStatus;
use App\Enums\AssetStatus;
use App\Enums\ImportDateFormat;
use App\Enums\PcCondition;
use App\Enums\PcStatus;
use App\Models\AssetImport;
use Illuminate\Validation\ValidationException;

/**
 * Saves the column mapping, value meanings, defaults and date order the
 * administrator confirmed, and checks every row against them (Phase 2.10 R6).
 *
 * The "Check rows" button is disabled on screen until the mapping is
 * acceptable; this is the same check, server-side, with the same reasons:
 * every required destination has a column (or, for a category, a default), no
 * destination is filled twice, and a date order is chosen whenever a mapped
 * date column holds a date that could be read either way.
 */
final class UpdateAssetImportMapping
{
    use GuardsAssetImport;

    public function __construct(
        private readonly ImportColumnMapper $mapper,
        private readonly AssetImportValues $values,
    ) {}

    /**
     * @param  array<string, string|null>  $mapping
     * @param  array<string, array<string, string|null>>  $valueMappings
     * @param  array<string, mixed>  $defaults
     */
    public function handle(AssetImport $import, array $mapping, array $valueMappings, array $defaults, ?ImportDateFormat $dateOrder): AssetImport
    {
        $this->assertStatus($import, [AssetImportStatus::Mapping, AssetImportStatus::Review]);

        $mode = $import->entity_mode;
        $columns = array_map(fn (array $c): int => (int) $c['index'], $import->headers ?? []);
        $mapping = array_filter($mapping, fn (?string $t): bool => $t !== null && $t !== '');

        $problems = $this->mapper->problems($mapping, $columns, $mode, $defaults);

        foreach ($valueMappings as $field => $meanings) {
            if (! in_array($field, ImportValueMapper::FIELDS, true)) {
                $problems["value_mappings.{$field}"] = 'Unknown value field.';

                continue;
            }
            foreach ($meanings as $meaning) {
                if ($meaning !== null && ! ImportValueMapper::isValid($field, $meaning, $mode)) {
                    $problems["value_mappings.{$field}"] = 'Choose a meaning from the list.';
                }
            }
        }

        $defaults = $this->defaults($defaults, $mode, $problems);

        if ($dateOrder === null && $this->values->dateSummary($import, $mapping)['required']) {
            $problems['date_order'] = 'Some dates in this file could be read day-first or month-first. Choose how this file writes dates.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $import->forceFill([
            'mapping' => $mapping,
            'value_mappings' => array_map(fn (array $m): array => array_filter($m, fn (?string $v): bool => $v !== null), $valueMappings),
            'defaults' => $defaults,
            'date_order' => $dateOrder,
            'status' => AssetImportStatus::Validating,
            'error_code' => null,
        ])->save();
        $revision = $this->nextRevision($import);

        ValidateAssetImportJob::dispatch($import->getKey(), $revision);

        return $import->refresh();
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, string>  $problems
     * @return array<string, mixed>
     */
    private function defaults(array $defaults, AssetImportEntityMode $mode, array &$problems): array
    {
        $out = [];
        $check = function (string $key, callable $valid) use ($defaults, &$out, &$problems): void {
            $value = $defaults[$key] ?? null;
            if ($value === null || $value === '') {
                return;
            }
            if (! is_string($value) || ! $valid($value)) {
                $problems["defaults.{$key}"] = 'Choose a default from the list.';

                return;
            }
            $out[$key] = $value;
        };

        $check('category', fn (string $v): bool => ImportValueMapper::isValid('category', $v, $mode));
        $check('pc_status', fn (string $v): bool => PcStatus::tryFrom($v) !== null);
        $check('asset_status', fn (string $v): bool => AssetStatus::tryFrom($v) !== null);
        $check('condition', fn (string $v): bool => PcCondition::tryFrom($v) !== null);
        if (! empty($defaults['location_from_sheet_name'])) {
            $out['location_from_sheet_name'] = true;
        }

        return $out;
    }
}
