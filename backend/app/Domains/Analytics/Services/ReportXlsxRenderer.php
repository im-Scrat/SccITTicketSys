<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\ReportDocument;
use App\Domains\Analytics\DTOs\ReportDocumentSection;
use App\Domains\Analytics\DTOs\ReportTable;
use App\Support\Exports\SafeValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Properties;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel workbook renderer (Phase 2.10, SDD DD-85/DD-91).
 *
 * Sheets: **Summary** (cover, filters, rule-based summary, headline figures),
 * one sheet per section with its tables and native charts, **Records** (every
 * record fetched, frozen header, auto-filter) and **Notes** (definitions and
 * limitations). The workbook carries the full records: it is the format the
 * paged documents point to when they cut a list short.
 *
 * Written with PhpSpreadsheet directly (not through maatwebsite's download
 * helper), so no temporary file is left in `storage/framework`, and with
 * {@see SafeValueBinder} so no cell becomes a formula. Each chart draws from a
 * small "Chart data" block beside its table — numbers only, gaps left empty —
 * so a chart never reads a cell that says "Before records".
 */
final class ReportXlsxRenderer
{
    private const HEADER_FILL = 'FFE9E6DF';

    public function render(ReportDocument $document, int $cellBudget): string
    {
        Cell::setValueBinder(new SafeValueBinder);

        $document = $this->withinCellBudget($document, $cellBudget);
        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator($document->organisation)
            ->setTitle($document->title.' — '.$document->periodLabel)
            ->setSubject($document->asOfLabel);

        $this->summarySheet($book->getActiveSheet(), $document);

        foreach ($document->sections as $section) {
            if ($section->key === 'headline') {
                continue;
            }

            $records = array_values(array_filter($section->tables, static fn (ReportTable $t): bool => $t->isRecords));
            $sheet = $book->createSheet();
            $sheet->setTitle($records !== [] ? 'Records' : $this->sheetName($section->title));

            $records !== []
                ? $this->recordsSheet($sheet, $records[0])
                : $this->sectionSheet($sheet, $section);
        }

        $this->notesSheet($book->createSheet(), $document);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'rpt');

        try {
            $writer = new Xlsx($book);
            $writer->setIncludeCharts(true);
            $writer->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }

    private function summarySheet(Worksheet $sheet, ReportDocument $document): void
    {
        $sheet->setTitle('Summary');
        $row = 1;
        $sheet->setCellValue("A{$row}", $document->title.' — '.$document->organisation);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row++;

        foreach ([$document->periodLabel, $document->comparisonLabel ? 'Compared with '.$document->comparisonLabel : null, $document->asOfLabel, $document->generatedLabel] as $line) {
            if ($line !== null) {
                $sheet->setCellValue("A{$row}", $line);
                $row++;
            }
        }

        $row++;
        $row = $this->writeTable($sheet, $row, ReportTable::make(
            'filters',
            'Filters',
            [['label', 'Filter'], ['value', 'Value']],
            array_map(static fn (array $f): array => [$f['label'], $f['value']], $document->filters),
        ));

        $sheet->setCellValue("A{$row}", 'Summary — '.$document->summaryLabel);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        foreach ([...$document->summary, ...$document->caveats] as $sentence) {
            $sheet->setCellValue("A{$row}", $sentence);
            $row++;
        }

        $row++;
        $headline = $document->sections[0] ?? null;
        if ($headline instanceof ReportDocumentSection && $headline->key === 'headline') {
            foreach ($headline->tables as $table) {
                $row = $this->writeTable($sheet, $row, $table);
            }
        }

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(60);
    }

    private function sectionSheet(Worksheet $sheet, ReportDocumentSection $section): void
    {
        $sheet->setCellValue('A1', $section->title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $row = 2;

        if ($section->intro !== null) {
            $sheet->setCellValue('A2', $section->intro);
            $row = 3;
        }

        $row++;
        $chartAnchorRow = $row;

        foreach ($section->tables as $table) {
            $row = $this->writeTable($sheet, $row, $table);
        }

        foreach ($section->notes as $note) {
            $sheet->setCellValue("A{$row}", $note);
            $row++;
        }

        $widest = max(array_map(static fn (ReportTable $t): int => count($t->columns), $section->tables ?: [ReportTable::make('x', 'x', [['a', 'a']], [])]));
        $dataColumn = $widest + 2;

        foreach ($section->charts as $chart) {
            if ($chart->isEmpty()) {
                continue;
            }
            [$chartObject, $used] = $this->chart($sheet, $chart, $dataColumn, $chartAnchorRow);
            $sheet->addChart($chartObject);
            $chartAnchorRow += max($used, 20) + 2;
        }

        for ($c = 1; $c <= $widest; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($c === 1 ? 34 : 16);
        }
    }

    private function recordsSheet(Worksheet $sheet, ReportTable $table): void
    {
        $sheet->fromArray([array_map(static fn (array $c): string => $c['label'], $table->columns)], null, 'A1');
        $last = Coordinate::stringFromColumnIndex(max(count($table->columns), 1));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);

        if ($table->rows !== []) {
            $sheet->fromArray($table->rows, null, 'A2', true);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}".max(count($table->rows) + 1, 1));

        foreach ($table->columns as $i => $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($column['key'] === 'title' ? 48 : 18);
        }

        $notes = array_values(array_filter([$table->note, $table->truncationNote()]));
        $row = count($table->rows) + 3;
        foreach ($notes as $note) {
            $sheet->setCellValue("A{$row}", $note);
            $row++;
        }
    }

    private function notesSheet(Worksheet $sheet, ReportDocument $document): void
    {
        $sheet->setTitle('Notes');
        $row = $this->writeTable($sheet, 1, ReportTable::make(
            'definitions',
            'How the figures are counted',
            [['term', 'Term'], ['definition', 'Definition']],
            array_map(static fn (array $d): array => [$d['term'], $d['definition']], $document->definitions),
        ));
        $sheet->setCellValue("A{$row}", 'Limitations');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        foreach ($document->limitations as $limitation) {
            $sheet->setCellValue("A{$row}", $limitation);
            $row++;
        }
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(100);
        $sheet->getStyle('B:B')->getAlignment()->setWrapText(true);
    }

    /** Writes a titled table and returns the next free row (one blank row after). */
    private function writeTable(Worksheet $sheet, int $row, ReportTable $table): int
    {
        $sheet->setCellValue("A{$row}", $table->title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $last = Coordinate::stringFromColumnIndex(max(count($table->columns), 1));
        $sheet->fromArray([array_map(static fn (array $c): string => $c['label'], $table->columns)], null, "A{$row}");
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $row++;

        foreach ($table->rows as $cells) {
            $sheet->fromArray([$cells], null, "A{$row}", true);
            foreach ($table->columns as $i => $column) {
                if ($column['type'] === ReportTable::TYPE_NUMBER && is_int($cells[$i] ?? null)) {
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($i + 1).$row)->getNumberFormat()->setFormatCode('#,##0');
                }
            }
            $row++;
        }

        foreach (array_filter([$table->note, $table->truncationNote()]) as $note) {
            $sheet->setCellValue("A{$row}", $note);
            $row++;
        }

        return $row + 1;
    }

    /**
     * A native chart over a numeric "Chart data" block written at
     * (`$column`, `$row`).
     *
     * @return array{0: Chart, 1: int} the chart and the rows its block uses
     */
    private function chart(Worksheet $sheet, ChartSpec $spec, int $column, int $row): array
    {
        $title = $sheet->getTitle();
        $col = static fn (int $offset): string => Coordinate::stringFromColumnIndex($column + $offset);

        $sheet->setCellValue($col(0).$row, 'Chart data — '.$spec->title);
        $sheet->getStyle($col(0).$row)->getFont()->setItalic(true);
        $header = $row + 1;
        $sheet->setCellValue($col(0).$header, 'Category');
        foreach ($spec->series as $i => $series) {
            $sheet->setCellValue($col($i + 1).$header, $series['name']);
        }
        foreach ($spec->categories as $j => $category) {
            $r = $header + 1 + $j;
            $sheet->setCellValue($col(0).$r, $category);
            foreach ($spec->series as $i => $series) {
                $value = $series['values'][$j] ?? null;
                if ($value !== null) {
                    $sheet->setCellValue($col($i + 1).$r, $value);
                }
            }
        }

        $count = count($spec->categories);
        $first = $header + 1;
        $lastRow = $header + $count;
        $ref = static fn (string $c, int $from, int $to): string => "'{$title}'!\${$c}\${$from}:\${$c}\${$to}";

        $labels = [];
        $values = [];
        foreach ($spec->series as $i => $series) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$title}'!\$".$col($i + 1).'$'.$header, null, 1);
            $dsv = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $ref($col($i + 1), $first, $lastRow), null, $count);
            if ($spec->kind !== ChartSpec::DONUT) {
                $dsv->setFillColor(ltrim($spec->colors[$i] ?? ReportPalette::CURRENT, '#'));
                if ($spec->kind === ChartSpec::LINE) {
                    $dsv->setLineColorProperties(ltrim($spec->colors[$i] ?? ReportPalette::CURRENT, '#'));
                    // 'dash': PhpSpreadsheet's own constant for it is misspelled (LINE_STYPE_DASH_DASH).
                    $dsv->setLineStyleProperties(2.0, '', $series['style'] === 'compare' ? 'dash' : Properties::LINE_STYLE_DASH_SOLID);
                    $dsv->setSmoothLine(false);
                }
            } else {
                $dsv->setFillColor(array_map(static fn (string $hex): string => ltrim($hex, '#'), $spec->colors));
            }
            $values[] = $dsv;
        }
        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $ref($col(0), $first, $lastRow), null, $count)];

        [$type, $grouping] = match ($spec->kind) {
            ChartSpec::LINE => [DataSeries::TYPE_LINECHART, DataSeries::GROUPING_STANDARD],
            ChartSpec::DONUT => [DataSeries::TYPE_DONUTCHART, null],
            default => [DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED],
        };

        $series = new DataSeries($type, $grouping, range(0, count($values) - 1), $labels, $categories, $values);
        if ($spec->kind === ChartSpec::BAR) {
            $series->setPlotDirection(DataSeries::DIRECTION_BAR);
        }

        $chart = new Chart(
            'chart_'.substr(sha1($title.$spec->title), 0, 8),
            new Title($spec->title),
            new Legend($spec->kind === ChartSpec::DONUT ? Legend::POSITION_RIGHT : Legend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [$series]),
            true,
            DataSeries::EMPTY_AS_GAP,
        );

        $chart->setTopLeftPosition($col(count($spec->series) + 2).$row);
        $chart->setBottomRightPosition($col(count($spec->series) + 11).($row + 20));

        return [$chart, $count + 2];
    }

    /**
     * Cut the records so the whole workbook stays inside the cell budget;
     * every other table is small and kept whole.
     */
    private function withinCellBudget(ReportDocument $document, int $budget): ReportDocument
    {
        $records = $document->recordsTable();

        if ($records === null) {
            return $document;
        }

        $columns = max(count($records->columns), 1);
        $limit = intdiv(max($budget, $columns), $columns);

        if ($records->shownRows() <= $limit) {
            return $document;
        }

        $sections = [];
        foreach ($document->sections as $section) {
            $sections[] = $section->withTables(array_map(
                static fn (ReportTable $t): ReportTable => $t->isRecords ? $t->limitedTo($limit) : $t,
                $section->tables,
            ));
        }

        return $document->withSections($sections);
    }

    /** Excel sheet names: at most 31 characters, none of []:*?/\ . */
    private function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $title));

        return mb_substr($name !== '' ? $name : 'Section', 0, 31);
    }
}
