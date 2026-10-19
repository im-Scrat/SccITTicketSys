<?php

declare(strict_types=1);

namespace App\Domains\Assets\Services\Import;

use App\Domains\Assets\DTOs\ImportIssue;
use App\Enums\ImportEntityType;
use App\Models\Asset;
use App\Models\PcUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds rows that are already in the register, or twice in the file (Phase
 * 2.10 R6, SDD DD-96).
 *
 * The database's unique indexes are case-sensitive and the form's unique rule
 * ignores archived records; an inventory spreadsheet has neither excuse. Here
 * every identifier is compared **case-insensitively** and **archived records
 * count** — re-importing a unit that was archived last term is a duplicate
 * ("restore it instead"), not a new record.
 *
 * A duplicate is skipped and the existing record left exactly as it was: an
 * import never updates. Where the same text is an identifier in the *other*
 * register (a PC's asset tag that is also an asset's), or a serial number
 * repeats (serials are not unique for PC units), the row is held for review
 * rather than skipped, because both can be legitimate.
 */
final class ImportDuplicateDetector
{
    /** @var array<string, array<string, array<string, list<array<string, mixed>>>>> register => column => lower(value) => records */
    private array $index = [];

    /**
     * Load the existing identifiers that could collide with these payloads.
     *
     * @param  iterable<array{0: ImportEntityType, 1: array<string, mixed>}>  $rows
     */
    public function prepare(iterable $rows): void
    {
        $values = [];
        foreach ($rows as [, $payload]) {
            foreach (['unit_code', 'asset_tag', 'hostname', 'serial_number'] as $field) {
                $value = $this->key($payload[$field] ?? null);
                if ($value !== null) {
                    $values[$value] = true;
                }
            }
        }
        // Array keys turn "12345" into an integer; bindings must stay text.
        $list = array_map('strval', array_keys($values));

        $this->index = [
            'pc' => $this->load(PcUnit::query()->withTrashed(), ['unit_code', 'asset_tag', 'hostname', 'serial_number'], $list),
            'asset' => $this->load(Asset::query()->withTrashed(), ['asset_tag', 'serial_number'], $list),
        ];
    }

    /**
     * Collisions with records already in the register.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ImportIssue>
     */
    public function existing(ImportEntityType $type, array $payload): array
    {
        $issues = [];
        $same = $type === ImportEntityType::PcUnit ? 'pc' : 'asset';
        $other = $type === ImportEntityType::PcUnit ? 'asset' : 'pc';
        $label = $type === ImportEntityType::PcUnit ? 'PC unit' : 'asset';
        $article = $type === ImportEntityType::PcUnit ? 'A' : 'An';

        $unique = $type === ImportEntityType::PcUnit ? ['unit_code', 'asset_tag', 'hostname'] : ['asset_tag', 'serial_number'];
        foreach ($unique as $field) {
            foreach ($this->find($same, $field, $payload[$field] ?? null) as $record) {
                $name = ImportFieldDictionary::label($field);
                $issues[] = $record['archived']
                    ? ImportIssue::make('duplicate_archived', $field, "An archived {$label} already has {$name} {$payload[$field]}. Restore it instead of importing it again.", $record)
                    : ImportIssue::make('duplicate_existing', $field, "{$article} {$label} with {$name} {$payload[$field]} already exists. This row will be skipped and the existing record left as it is.", $record);

                break 2;
            }
        }

        $crossFields = $type === ImportEntityType::PcUnit ? ['asset_tag' => ['asset_tag']] : ['asset_tag' => ['asset_tag', 'unit_code']];
        foreach ($crossFields as $field => $otherFields) {
            foreach ($otherFields as $otherField) {
                foreach ($this->find($other, $otherField, $payload[$field] ?? null) as $record) {
                    $issues[] = ImportIssue::make('cross_table_identifier', $field, "{$payload[$field]} is already the ".ImportFieldDictionary::label($otherField).' of '.($other === 'pc' ? 'a PC unit' : 'an asset').'. Check it is a different item.', $record);

                    break 2;
                }
            }
        }

        $serialSources = $type === ImportEntityType::PcUnit ? ['pc', 'asset'] : ['pc'];
        foreach ($serialSources as $register) {
            foreach ($this->find($register, 'serial_number', $payload['serial_number'] ?? null) as $record) {
                $issues[] = ImportIssue::make('possible_duplicate_serial', 'serial_number', "Serial number {$payload['serial_number']} is already recorded on ".($register === 'pc' ? 'a PC unit' : 'an asset').'. It may be the same item.', $record);

                break 2;
            }
        }

        return $issues;
    }

    /**
     * Rows that repeat one another inside the file. Identical repeats after the
     * first are skipped (`repeat_in_file`); rows that share an identifier but
     * differ are all invalid until corrected or excluded (`duplicate_in_file`).
     *
     * @param  array<int, array{0: ImportEntityType, 1: array<string, mixed>}>  $rows  row number => [type, payload]
     * @return array<int, list<ImportIssue>>
     */
    public function withinFile(array $rows): array
    {
        $groups = [];
        foreach ($rows as $number => [$type, $payload]) {
            $fields = $type === ImportEntityType::PcUnit ? ['unit_code', 'asset_tag', 'hostname'] : ['asset_tag', 'serial_number'];
            foreach ($fields as $field) {
                $value = $this->key($payload[$field] ?? null);
                if ($value !== null) {
                    $groups[$type->value.'|'.$field.'|'.$value][] = $number;
                }
            }
        }

        $issues = [];
        foreach ($groups as $group => $numbers) {
            if (count($numbers) < 2) {
                continue;
            }
            [, $field] = explode('|', $group, 3);
            $first = $numbers[0];
            $fingerprint = fn (int $n): string => (string) json_encode($this->sorted($rows[$n][1]));
            $identical = array_filter($numbers, fn (int $n): bool => $fingerprint($n) === $fingerprint($first)) === $numbers;
            $value = (string) $rows[$first][1][$field];

            foreach ($numbers as $number) {
                $others = array_values(array_diff($numbers, [$number]));
                if ($identical) {
                    if ($number !== $first) {
                        $issues[$number][$field] = ImportIssue::make('repeat_in_file', $field, "This row repeats row {$first} exactly. It will be skipped.", ['rows' => [$first]], ImportIssue::SCOPE_FILE);
                    }
                } else {
                    $issues[$number][$field] = ImportIssue::make('duplicate_in_file', $field, ImportFieldDictionary::label($field)." {$value} is also used by row".(count($others) > 1 ? 's ' : ' ').implode(', ', $others).'. Correct one of them or exclude it.', ['rows' => $others], ImportIssue::SCOPE_FILE);
                }
            }
        }

        return array_map(fn (array $byField): array => array_values($byField), $issues);
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $values
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function load(Builder $query, array $columns, array $values): array
    {
        $index = [];
        if ($values === []) {
            return $index;
        }

        $isPc = $query->getModel() instanceof PcUnit;
        foreach (array_chunk($values, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $clone = clone $query;
            $clone->where(function (Builder $q) use ($columns, $placeholders, $chunk): void {
                foreach ($columns as $column) {
                    $q->orWhereRaw("lower({$column}) in ({$placeholders})", $chunk);
                }
            });

            foreach ($clone->get(['id', 'uuid', 'deleted_at', ...$columns, ...($isPc ? ['pc_name'] : ['name'])]) as $record) {
                $entry = [
                    'record_type' => $isPc ? 'pc_unit' : 'asset',
                    'uuid' => $record->getAttribute('uuid'),
                    'label' => $isPc ? $record->getAttribute('unit_code').' · '.$record->getAttribute('pc_name') : $record->getAttribute('asset_tag'),
                    'archived' => $record->getAttribute('deleted_at') !== null,
                ];
                foreach ($columns as $column) {
                    $key = $this->key($record->getAttribute($column));
                    if ($key !== null) {
                        $index[$column][$key][] = $entry;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function find(string $register, string $column, mixed $value): array
    {
        $key = $this->key($value);

        return $key === null ? [] : ($this->index[$register][$column][$key] ?? []);
    }

    private function key(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sorted(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                ksort($v);
                $payload[$k] = $v;
            }
        }

        return $payload;
    }
}
