<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Enums\AssetImportEntityMode;
use App\Enums\ImportDateFormat;
use App\Models\AssetImport;
use Illuminate\Support\Facades\DB;

/**
 * The distinct values in an import's columns, and what they are proposed to
 * mean (Phase 2.10 R6, Appendix B.3/B.4) — the "value meanings" tables and the
 * date-order question on the mapping screen.
 *
 * Computed in SQL over the stored raw cells, so asking about a column of a
 * 5,000-row file never loads 5,000 rows into PHP.
 */
final class AssetImportValues
{
    /** Distinct values shown per column; the rest are summarised. */
    public const LIMIT = 200;

    /**
     * The value-meaning fields a target feeds, for this kind of file.
     *
     * @return list<string>
     */
    public static function fieldsFor(string $target, AssetImportEntityMode $mode): array
    {
        return match ($target) {
            'status' => match ($mode) {
                AssetImportEntityMode::PcUnits => ['status.pc_unit'],
                AssetImportEntityMode::Assets => ['status.asset'],
                AssetImportEntityMode::Mixed => ['status.pc_unit', 'status.asset'],
            },
            'condition' => ['condition'],
            'category' => $mode === AssetImportEntityMode::PcUnits ? [] : ['category'],
            default => [],
        };
    }

    /**
     * @return list<array{raw: string, normalized: string, rows: int}>
     */
    public function distinct(AssetImport $import, int $column, int $limit = self::LIMIT): array
    {
        // An integer the server holds, never request text: safe to inline, and
        // it must be — PostgreSQL treats two bound placeholders as two
        // different expressions, so GROUP BY could never match the SELECT.
        $cell = 'trim(raw->>'.DB::getPdo()->quote((string) (int) $column).')';

        return DB::table('asset_import_rows')
            ->where('asset_import_id', $import->getKey())
            ->whereRaw("coalesce({$cell}, '') <> ''")
            ->selectRaw("min({$cell}) as raw, lower({$cell}) as grouped, count(*) as row_total")
            ->groupByRaw("lower({$cell})")
            ->orderByDesc('row_total')
            ->limit($limit)
            ->get()
            ->map(fn (object $r): array => [
                'raw' => (string) $r->raw,
                'normalized' => ImportFieldDictionary::normalize((string) $r->raw),
                'rows' => (int) $r->row_total,
            ])
            ->all();
    }

    /**
     * Proposed meanings for every value of every mapped status, condition and
     * category column: only exact, synonym and likely matches are filled in.
     *
     * @param  array<string, string|null>  $mapping
     * @return array<string, array<string, string>>
     */
    public function suggestedMeanings(AssetImport $import, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $column => $target) {
            if ($target === null) {
                continue;
            }
            foreach (self::fieldsFor($target, $import->entity_mode) as $field) {
                foreach ($this->distinct($import, (int) $column) as $value) {
                    $suggestion = ImportValueMapper::suggest($field, $value['raw'], $import->entity_mode);
                    if ($suggestion['value'] !== null && in_array($suggestion['confidence'], ['exact', 'synonym', 'likely'], true)) {
                        $out[$field][$value['normalized']] = $suggestion['value'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The value-meaning tables for the mapping screen: every distinct value,
     * its row count, the proposed and the chosen meaning. Unknown values first.
     *
     * @param  array<string, string|null>  $mapping
     * @return list<array<string, mixed>>
     */
    public function meaningTables(AssetImport $import, array $mapping): array
    {
        $tables = [];
        foreach ($mapping as $column => $target) {
            if ($target === null) {
                continue;
            }
            foreach (self::fieldsFor($target, $import->entity_mode) as $field) {
                $values = [];
                foreach ($this->distinct($import, (int) $column) as $value) {
                    $suggestion = ImportValueMapper::suggest($field, $value['raw'], $import->entity_mode);
                    $chosen = $import->value_mappings[$field][$value['normalized']] ?? null;
                    $values[] = [
                        ...$value,
                        'suggested' => $suggestion['value'],
                        'confidence' => $suggestion['confidence'],
                        'choices' => $suggestion['options'],
                        'hint' => $suggestion['hint'],
                        'meaning' => $chosen,
                    ];
                }
                usort($values, fn (array $a, array $b): int => [($a['meaning'] ?? $a['suggested']) !== null, -$a['rows']] <=> [($b['meaning'] ?? $b['suggested']) !== null, -$b['rows']]);

                $tables[] = [
                    'field' => $field,
                    'column' => (int) $column,
                    'target' => $target,
                    'options' => ImportValueMapper::options($field, $import->entity_mode),
                    'values' => $values,
                ];
            }
        }

        return $tables;
    }

    /**
     * Whether the mapped date columns need a date order, with evidence.
     *
     * @param  array<string, string|null>  $mapping
     * @return array{required: bool, ambiguous: int, evidence: string|null, samples: list<array{raw: string, dmy: string|null, mdy: string|null}>}
     */
    public function dateSummary(AssetImport $import, array $mapping): array
    {
        $ambiguous = 0;
        $evidence = ['dmy' => 0, 'mdy' => 0];
        $samples = [];

        foreach ($mapping as $column => $target) {
            if (! in_array($target, ['purchase_date', 'warranty_expiration'], true)) {
                continue;
            }
            foreach ($this->distinct($import, (int) $column, 1000) as $value) {
                $proof = ImportDateParser::evidence($value['raw']);
                if ($proof !== null) {
                    $evidence[$proof->value] += $value['rows'];
                }
                if (ImportDateParser::isAmbiguous($value['raw'])) {
                    $ambiguous += $value['rows'];
                    if (count($samples) < 3) {
                        $samples[] = [
                            'raw' => $value['raw'],
                            'dmy' => ImportDateParser::parse($value['raw'], [], ImportDateFormat::DayFirst)['value'],
                            'mdy' => ImportDateParser::parse($value['raw'], [], ImportDateFormat::MonthFirst)['value'],
                        ];
                    }
                }
            }
        }

        $proven = match (true) {
            $evidence['dmy'] > 0 && $evidence['mdy'] === 0 => ImportDateFormat::DayFirst->value,
            $evidence['mdy'] > 0 && $evidence['dmy'] === 0 => ImportDateFormat::MonthFirst->value,
            default => null,
        };

        return ['required' => $ambiguous > 0, 'ambiguous' => $ambiguous, 'evidence' => $proven, 'samples' => $samples];
    }
}
