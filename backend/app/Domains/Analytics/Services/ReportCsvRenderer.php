<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportDocument;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Support\Exports\SafeValueBinder;

/**
 * CSV renderer (Phase 2.10, SDD DD-85/DD-91): **one** table — the records when
 * the document has them, otherwise the first section table after the headline
 * — because a CSV has no sheets and a file mixing tables is unreadable by the
 * programs CSV exists for.
 *
 * UTF-8 with a byte-order mark, so Excel reads accented room names correctly,
 * and every text cell that a spreadsheet would treat as a formula is prefixed
 * with an apostrophe (`'=HYPERLINK(…)`), the CSV equivalent of
 * {@see SafeValueBinder}.
 */
final class ReportCsvRenderer
{
    public function render(ReportDocument $document): string
    {
        $table = $document->recordsTable() ?? $this->firstSectionTable($document);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false || $table === null) {
            return "\u{FEFF}";
        }

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, array_map(fn (array $c): string => $this->safe($c['label']), $table->columns), ',', '"', '');

        foreach ($table->rows as $row) {
            fputcsv($handle, array_map(fn (string|int|float|null $cell): string => is_string($cell) ? $this->safe($cell) : (string) ($cell ?? ''), $row), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function firstSectionTable(ReportDocument $document): ?ReportTable
    {
        foreach ($document->sections as $section) {
            if ($section->key !== 'headline' && $section->tables !== []) {
                return $section->tables[0];
            }
        }

        return $document->tables()[0] ?? null;
    }

    private function safe(string $value): string
    {
        return SafeValueBinder::needsEscaping($value) ? "'".$value : $value;
    }
}
