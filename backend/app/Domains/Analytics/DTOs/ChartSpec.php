<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

/**
 * A chart in a report document, as data (Phase 2.10, SDD DD-91).
 *
 * One description, drawn natively by each format — an Excel chart, a Word
 * chart, an SVG in the PDF, the chart kit on the print page — so no format
 * draws a different picture of the same figures. Every chart in a document is
 * followed by a table of the same numbers ({@see $tableKey}); a chart is never
 * the only place a value appears.
 *
 * `series[].style`: `current` (the period), `compare` (dashed), or `slices`
 * (a donut, one colour per category from {@see $colors}).
 */
final readonly class ChartSpec
{
    public const LINE = 'line';

    public const DONUT = 'donut';

    public const BAR = 'bar';

    /**
     * @param  list<string>  $categories
     * @param  list<array{name: string, values: list<int|float|null>, style: string}>  $series
     * @param  list<string>  $colors  hex, one per category (donut) or per series
     */
    public function __construct(
        public string $kind,
        public string $title,
        public array $categories,
        public array $series,
        public array $colors,
        public string $description,
        public ?string $tableKey = null,
        public string $valueLabel = 'Tickets',
        /** @var list<string> stable keys per category (status slugs, bucket keys) — the screen maps them to theme tokens */
        public array $keys = [],
    ) {}

    /** Nothing to draw: every value is zero or unknown. */
    public function isEmpty(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series['values'] as $value) {
                if ($value !== null && $value != 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * For formats that cannot draw a gap (Word charts): categories nobody has
     * data for are removed, and a series that still has an unknown value is
     * left out rather than drawn through zero — unknown is never zero. The
     * table under the chart still carries every value. Null when nothing is
     * left to draw.
     */
    public function withoutUnknown(): ?self
    {
        $keep = [];
        foreach (array_keys($this->categories) as $i) {
            foreach ($this->series as $series) {
                if (($series['values'][$i] ?? null) !== null) {
                    $keep[] = $i;
                    break;
                }
            }
        }

        $series = [];
        $colors = [];
        foreach ($this->series as $s => $item) {
            $values = array_map(static fn (int $i): int|float|null => $item['values'][$i] ?? null, $keep);
            if (in_array(null, $values, true)) {
                continue;
            }
            $series[] = ['name' => $item['name'], 'values' => $values, 'style' => $item['style']];
            if ($this->kind !== self::DONUT) {
                $colors[] = $this->colors[$s] ?? $this->colors[0] ?? '#1d3f8f';
            }
        }

        if ($keep === [] || $series === []) {
            return null;
        }

        return new self(
            kind: $this->kind,
            title: $this->title,
            categories: array_map(fn (int $i): string => $this->categories[$i], $keep),
            series: $series,
            colors: $this->kind === self::DONUT ? array_map(fn (int $i): string => $this->colors[$i] ?? '#6b6f76', $keep) : $colors,
            description: $this->description,
            tableKey: $this->tableKey,
            valueLabel: $this->valueLabel,
            keys: $this->keys !== [] ? array_map(fn (int $i): string => $this->keys[$i] ?? '', $keep) : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'categories' => $this->categories,
            'series' => $this->series,
            'colors' => $this->colors,
            'description' => $this->description,
            'table_key' => $this->tableKey,
            'value_label' => $this->valueLabel,
            'keys' => $this->keys,
        ];
    }
}
