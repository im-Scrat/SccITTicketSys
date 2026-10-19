<?php

declare(strict_types=1);

namespace App\Domains\Analytics\DTOs;

use App\Enums\ReportContentProfile;
use App\Enums\ReportType;

/**
 * The content of a report document, independent of its format (Phase 2.10,
 * SDD DD-91).
 *
 * `ReportDocumentBuilder` is the only thing that makes one, from the same
 * service payload the screen shows; the XLSX, CSV, DOCX and PDF renderers and
 * the print page only lay it out. A figure therefore cannot differ between the
 * dashboard, a download, an email attachment and a printout — there is one
 * place it is computed and one place it is put into words.
 */
final readonly class ReportDocument
{
    /**
     * @param  list<array{label: string, value: string}>  $filters
     * @param  list<string>  $summary
     * @param  list<string>  $caveats
     * @param  list<ReportDocumentSection>  $sections
     * @param  list<array{term: string, definition: string}>  $definitions
     * @param  list<string>  $limitations
     */
    public function __construct(
        public ReportType $type,
        public string $title,
        public string $organisation,
        public string $periodLabel,
        public ?string $comparisonLabel,
        public string $timezoneLabel,
        public string $asOfLabel,
        public string $generatedLabel,
        public array $filters,
        public array $summary,
        public string $summaryLabel,
        public array $caveats,
        public array $sections,
        public array $definitions,
        public array $limitations,
        public ReportContentProfile $profile,
        public bool $recordsIncluded,
        /** The AI interpretation this document carries, if any (Phase 2.10 R5). */
        public ?int $narrativeId = null,
    ) {}

    /**
     * Every table in reading order.
     *
     * @return list<ReportTable>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->sections as $section) {
            foreach ($section->tables as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    public function recordsTable(): ?ReportTable
    {
        foreach ($this->tables() as $table) {
            if ($table->isRecords) {
                return $table;
            }
        }

        return null;
    }

    /** Rows across every table — what the paged formats' budget counts. */
    public function rowCount(): int
    {
        return array_sum(array_map(static fn (ReportTable $t): int => $t->shownRows(), $this->tables()));
    }

    /**
     * A copy whose tables are limited so the whole document carries at most
     * `$budget` rows. Summary tables are kept whole (they are small and the
     * figures depend on them); the records table takes what is left.
     */
    public function withRowBudget(int $budget): self
    {
        $records = $this->recordsTable();

        if ($records === null || $this->rowCount() <= $budget) {
            return $this;
        }

        $fixed = $this->rowCount() - $records->shownRows();
        $limit = max($budget - $fixed, 0);
        $sections = [];

        foreach ($this->sections as $section) {
            $sections[] = $section->withTables(array_map(
                static fn (ReportTable $t): ReportTable => $t->isRecords ? $t->limitedTo($limit) : $t,
                $section->tables,
            ));
        }

        return $this->withSections($sections);
    }

    /**
     * @param  list<ReportDocumentSection>  $sections
     */
    public function withSections(array $sections): self
    {
        return new self(
            $this->type, $this->title, $this->organisation, $this->periodLabel, $this->comparisonLabel,
            $this->timezoneLabel, $this->asOfLabel, $this->generatedLabel, $this->filters, $this->summary,
            $this->summaryLabel, $this->caveats, $sections, $this->definitions, $this->limitations,
            $this->profile, $this->recordsIncluded, $this->narrativeId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'organisation' => $this->organisation,
            'period_label' => $this->periodLabel,
            'comparison_label' => $this->comparisonLabel,
            'timezone_label' => $this->timezoneLabel,
            'as_of_label' => $this->asOfLabel,
            'generated_label' => $this->generatedLabel,
            'filters' => $this->filters,
            'summary' => $this->summary,
            'summary_label' => $this->summaryLabel,
            'caveats' => $this->caveats,
            'sections' => array_map(static fn (ReportDocumentSection $s): array => $s->toArray(), $this->sections),
            'definitions' => $this->definitions,
            'limitations' => $this->limitations,
            'profile' => $this->profile->value,
            'records_included' => $this->recordsIncluded,
        ];
    }
}
