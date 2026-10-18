<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

/**
 * One table in a report document (Phase 2.10, SDD DD-91).
 *
 * Cells are plain scalars, already in their final words ("Open", "LAB-101",
 * "18 Oct 2026") — every renderer prints them as given and none of them
 * formats or interprets data, so the four formats cannot disagree. Column
 * types only steer alignment and spreadsheet number formats.
 *
 * `totalRows` is how many rows the report has; `rows` is how many this document
 * carries. When a budget cut the list short, `truncated` is true and every
 * renderer says so in words next to the table.
 */
final readonly class ReportTable
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_PERCENT = 'percent';

    public const TYPE_CODE = 'code';

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public ?int $totalRows = null,
        public bool $truncated = false,
        public ?string $note = null,
        public bool $isRecords = false,
        /** @var list<array{path: string, query: array<string, string>}|null>|null one drill-down per row, for the screen */
        public ?array $links = null,
    ) {}

    /**
     * @param  list<array{0: string, 1: string, 2?: string}>  $columns  [key, label, type]
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function make(string $key, string $title, array $columns, array $rows, ?string $note = null): self
    {
        return new self(
            key: $key,
            title: $title,
            columns: array_map(static fn (array $c): array => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? self::TYPE_TEXT], $columns),
            rows: $rows,
            totalRows: count($rows),
            note: $note,
        );
    }

    public function shownRows(): int
    {
        return count($this->rows);
    }

    /** "Showing 600 of 1,240 records — every record is in the Excel export." */
    public function truncationNote(): ?string
    {
        if (! $this->truncated || $this->totalRows === null) {
            return null;
        }

        return sprintf(
            'Showing the first %s of %s rows. The Excel export carries every row.',
            number_format($this->shownRows()),
            number_format($this->totalRows),
        );
    }

    /**
     * A copy with a drill-down link per row (null where a row has none).
     *
     * @param  list<array{path: string, query: array<string, string>}|null>  $links
     */
    public function withLinks(array $links): self
    {
        return new self($this->key, $this->title, $this->columns, $this->rows, $this->totalRows, $this->truncated, $this->note, $this->isRecords, $links);
    }

    /**
     * A copy holding only the first `$limit` rows, marked truncated if it lost any.
     */
    public function limitedTo(int $limit): self
    {
        if (count($this->rows) <= $limit) {
            return $this;
        }

        return new self(
            key: $this->key,
            title: $this->title,
            columns: $this->columns,
            rows: array_slice($this->rows, 0, max($limit, 0)),
            totalRows: $this->totalRows ?? count($this->rows),
            truncated: true,
            note: $this->note,
            isRecords: $this->isRecords,
            links: $this->links !== null ? array_slice($this->links, 0, max($limit, 0)) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'total_rows' => $this->totalRows,
            'truncated' => $this->truncated,
            'truncation_note' => $this->truncationNote(),
            'note' => $this->note,
            'is_records' => $this->isRecords,
            'links' => $this->links,
        ];
    }
}
