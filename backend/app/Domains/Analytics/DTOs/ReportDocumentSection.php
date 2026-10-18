<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

/**
 * One section of a report document (Phase 2.10, SDD DD-91): a heading, a
 * sentence of purpose, headline figures, charts each followed by its table,
 * further tables, and notes.
 */
final readonly class ReportDocumentSection
{
    /**
     * @param  list<ReportKpi>  $kpis
     * @param  list<ChartSpec>  $charts
     * @param  list<ReportTable>  $tables
     * @param  list<string>  $paragraphs
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $intro = null,
        public array $kpis = [],
        public array $charts = [],
        public array $tables = [],
        public array $paragraphs = [],
        public array $notes = [],
    ) {}

    public function table(string $key): ?ReportTable
    {
        foreach ($this->tables as $table) {
            if ($table->key === $key) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param  list<ReportTable>  $tables
     */
    public function withTables(array $tables): self
    {
        return new self($this->key, $this->title, $this->intro, $this->kpis, $this->charts, $tables, $this->paragraphs, $this->notes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'intro' => $this->intro,
            'kpis' => array_map(static fn (ReportKpi $k): array => $k->toArray(), $this->kpis),
            'charts' => array_map(static fn (ChartSpec $c): array => $c->toArray(), $this->charts),
            'tables' => array_map(static fn (ReportTable $t): array => $t->toArray(), $this->tables),
            'paragraphs' => $this->paragraphs,
            'notes' => $this->notes,
        ];
    }
}
