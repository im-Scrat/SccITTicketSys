<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\AssetImportEntityMode;
use App\Enums\ImportEntityType;

/**
 * Proposes a target for every column and checks a confirmed mapping (Phase
 * 2.10 R6).
 *
 * One target per column and one column per target: when two columns would
 * both fill "Serial number", the more confident keeps it (the first on a tie)
 * and the other is left for the administrator with "Check this".
 */
final class ImportColumnMapper
{
    private const RANK = ['exact' => 4, 'synonym' => 3, 'likely' => 2, 'ambiguous' => 1, 'none' => 0];

    /**
     * @param  list<array{index: int, letter: string, header: string, samples: list<string>}>  $headers
     * @return array{columns: list<array<string, mixed>>, template_match: bool}
     */
    public function suggest(array $headers, AssetImportEntityMode $mode): array
    {
        $columns = [];
        foreach ($headers as $header) {
            $match = ImportFieldDictionary::match($header['header'], $mode);
            $columns[] = [
                ...$header,
                'target' => $match['target'],
                'confidence' => $match['confidence'],
                'alternatives' => $match['alternatives'],
                'never_imported' => $match['never_imported'],
                'quantity' => in_array(ImportFieldDictionary::normalize($header['header']), ImportFieldDictionary::QUANTITY_HEADERS, true),
            ];
        }

        $owner = [];
        foreach ($columns as $i => $column) {
            $target = $column['target'];
            if ($target === null) {
                continue;
            }
            if (! isset($owner[$target])) {
                $owner[$target] = $i;

                continue;
            }
            $holder = $owner[$target];
            if (self::RANK[$column['confidence']] > self::RANK[$columns[$holder]['confidence']]) {
                $columns[$holder] = [...$columns[$holder], 'target' => null, 'confidence' => 'ambiguous', 'alternatives' => [$target]];
                $owner[$target] = $i;
            } else {
                $columns[$i] = [...$column, 'target' => null, 'confidence' => 'ambiguous', 'alternatives' => [$target]];
            }
        }

        $templateMatch = $columns !== []
            && array_filter($columns, fn (array $c): bool => $c['confidence'] !== 'exact') === []
            && $this->missingRequired($this->asMapping($columns), $mode) === [];

        return ['columns' => $columns, 'template_match' => $templateMatch];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, string>
     */
    public function asMapping(array $columns): array
    {
        $mapping = [];
        foreach ($columns as $column) {
            if ($column['target'] !== null && in_array($column['confidence'], ['exact', 'synonym', 'likely'], true)) {
                $mapping[(string) $column['index']] = (string) $column['target'];
            }
        }

        return $mapping;
    }

    /**
     * Problems with a mapping the administrator submitted, as messages keyed
     * by field (empty when it is acceptable).
     *
     * @param  array<string, string|null>  $mapping  column index => target
     * @param  list<int>  $columns  the column indexes the sheet has
     * @param  array<string, mixed>  $defaults
     * @return array<string, string>
     */
    public function problems(array $mapping, array $columns, AssetImportEntityMode $mode, array $defaults = []): array
    {
        $problems = [];
        $seen = [];
        foreach ($mapping as $index => $target) {
            if ($target === null || $target === '') {
                continue;
            }
            if (! in_array((int) $index, $columns, true)) {
                $problems["mapping.{$index}"] = 'That column is not in the sheet.';
            } elseif (! ImportFieldDictionary::exists($target) || ! ImportFieldDictionary::appliesTo($target, $mode)) {
                $problems["mapping.{$index}"] = 'That destination cannot be imported for this kind of file.';
            } elseif (isset($seen[$target])) {
                $problems["mapping.{$index}"] = ImportFieldDictionary::label($target).' is already filled from another column.';
            }
            $seen[$target] = true;
        }

        foreach ($this->missingRequired($mapping, $mode, $defaults) as $target) {
            $problems['mapping'] = 'Choose a column for '.ImportFieldDictionary::label($target).'.';
        }

        return $problems;
    }

    /**
     * Required targets without a column (or, for a category, a default).
     *
     * @param  array<string, string|null>  $mapping
     * @param  array<string, mixed>  $defaults
     * @return list<string>
     */
    public function missingRequired(array $mapping, AssetImportEntityMode $mode, array $defaults = []): array
    {
        $mapped = array_values(array_filter($mapping));
        $required = match ($mode) {
            AssetImportEntityMode::PcUnits => ImportFieldDictionary::required(ImportEntityType::PcUnit),
            AssetImportEntityMode::Assets => ImportFieldDictionary::required(ImportEntityType::Asset),
            // A mixed file needs its category to tell the registers apart; each
            // register's own requirements are then checked row by row.
            AssetImportEntityMode::Mixed => ['category'],
        };

        return array_values(array_filter($required, function (string $target) use ($mapped, $defaults): bool {
            if (in_array($target, $mapped, true)) {
                return false;
            }

            return ! ($target === 'category' && ! empty($defaults['category']));
        }));
    }
}
