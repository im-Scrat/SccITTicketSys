<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ChartSpec;
use App\Domains\Analytics\DTOs\ReportDocument;
use App\Domains\Analytics\DTOs\ReportTable;
use LogicException;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Word renderer (Phase 2.10, SDD DD-85/DD-91).
 *
 * **Escaping is a precondition, not a default.** PhpWord writes text runs as
 * raw XML unless `Settings::setOutputEscapingEnabled(true)` — so a room called
 * `<w:fldSimple w:instr="HYPERLINK …"/>` would become a live field in the
 * document. AppServiceProvider enables it at boot and this renderer refuses to
 * run if it is ever off. Every value goes in through `addText()`; nothing
 * reaches `Html::addHtml()` or a template processor.
 *
 * Tables repeat their header row on each page (`tblHeader`) and never split a
 * row (`cantSplit`); the footer numbers pages with PAGE/NUMPAGES fields. Charts
 * are native Word charts in the light palette with data labels off — Word's
 * defaults (Office colours, a label on every point) would not match the screen.
 * Word draws every line series solid, so the compared period is told apart by
 * colour and legend here; the table under each chart carries every figure.
 */
final class ReportDocxRenderer
{
    private const FONT = 'Arial';

    /**
     * @param  array{paper?: string, orientation?: string}  $options
     */
    public function render(ReportDocument $document, array $options = []): string
    {
        if (! Settings::isOutputEscapingEnabled()) {
            throw new LogicException('PhpWord output escaping must be enabled before rendering a report.');
        }

        $word = new PhpWord;
        $word->setDefaultFontName(self::FONT);
        $word->setDefaultFontSize(10);
        $word->getDocInfo()->setCreator($document->organisation)->setTitle($document->title)->setSubject($document->periodLabel);
        $word->addTitleStyle(1, ['size' => 18, 'bold' => true], ['spaceAfter' => 120]);
        $word->addTitleStyle(2, ['size' => 14, 'bold' => true], ['spaceBefore' => 240, 'spaceAfter' => 120, 'keepNext' => true]);
        $word->addTitleStyle(3, ['size' => 11, 'bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true]);

        $landscape = ($options['orientation'] ?? 'portrait') === 'landscape';
        $section = $word->addSection([
            'paperSize' => ($options['paper'] ?? 'a4') === 'letter' ? 'Letter' : 'A4',
            'orientation' => $landscape ? 'landscape' : 'portrait',
            'marginTop' => Converter::cmToTwip(1.8),
            'marginBottom' => Converter::cmToTwip(1.8),
            'marginLeft' => Converter::cmToTwip(1.8),
            'marginRight' => Converter::cmToTwip(1.8),
        ]);
        $section->addFooter()->addPreserveText(
            $document->organisation.' · '.$document->title.' · Page {PAGE} of {NUMPAGES}',
            ['size' => 8, 'color' => '555555'],
            ['alignment' => Jc::END],
        );

        $width = $landscape ? 24.0 : 17.4;

        $section->addTitle($document->title, 1);
        $section->addText($document->organisation, ['bold' => true]);
        $section->addText($document->periodLabel);
        if ($document->comparisonLabel !== null) {
            $section->addText('Compared with '.$document->comparisonLabel);
        }
        $section->addText($document->asOfLabel, ['color' => '444444']);
        $section->addText($document->generatedLabel, ['color' => '444444']);

        $section->addTitle('Filters', 3);
        $this->table($section, ReportTable::make('filters', 'Filters', [['label', 'Filter'], ['value', 'Value']], array_map(static fn (array $f): array => [$f['label'], $f['value']], $document->filters)), $width, false);

        $section->addTitle('Summary', 3);
        $section->addText($document->summaryLabel, ['italic' => true, 'color' => '444444']);
        foreach ($document->summary as $sentence) {
            $section->addListItem($sentence);
        }
        foreach ($document->caveats as $caveat) {
            $section->addText($caveat, ['italic' => true]);
        }

        foreach ($document->sections as $part) {
            $section->addTitle($part->title, 2);
            if ($part->intro !== null) {
                $section->addText($part->intro);
            }

            $charted = [];
            foreach ($part->charts as $chart) {
                $chart = $chart->isEmpty() ? null : $chart->withoutUnknown();
                if ($chart === null) {
                    continue;
                }
                $section->addTitle($chart->title, 3);
                $this->chart($section, $chart, $width);
                $section->addText($chart->description, ['size' => 9, 'color' => '444444']);
                $charted[] = $chart->tableKey;
            }

            foreach ($part->tables as $table) {
                if (! in_array($table->key, $charted, true) || count($part->tables) > 1) {
                    $section->addTitle($table->title, 3);
                }
                $this->table($section, $table, $width);
            }

            foreach ($part->notes as $note) {
                $section->addText($note, ['size' => 9, 'color' => '444444']);
            }
        }

        $section->addTitle('How the figures are counted', 2);
        $this->table($section, ReportTable::make('definitions', 'Definitions', [['term', 'Term'], ['definition', 'Definition']], array_map(static fn (array $d): array => [$d['term'], $d['definition']], $document->definitions)), $width);

        $section->addTitle('Limitations', 2);
        foreach ($document->limitations as $limitation) {
            $section->addListItem($limitation);
        }

        $path = tempnam(sys_get_temp_dir(), 'rpt');

        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private function table(Section $section, ReportTable $table, float $widthCm, bool $header = true): void
    {
        $columns = max(count($table->columns), 1);
        $cell = (int) round(Converter::cmToTwip($widthCm / $columns));
        $grid = $section->addTable(['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct']);

        if ($header) {
            $grid->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
            foreach ($table->columns as $column) {
                $grid->addCell($cell, ['bgColor' => 'E9E6DF'])->addText($column['label'], ['bold' => true]);
            }
        }

        foreach ($table->rows as $row) {
            $grid->addRow(null, ['cantSplit' => true]);
            foreach ($table->columns as $i => $column) {
                $value = $row[$i] ?? '';
                $numeric = in_array($column['type'], [ReportTable::TYPE_NUMBER, ReportTable::TYPE_PERCENT], true);
                $grid->addCell($cell)->addText(
                    is_int($value) ? number_format($value) : (string) $value,
                    $column['type'] === ReportTable::TYPE_CODE ? ['name' => 'Courier New'] : [],
                    $numeric ? ['alignment' => Jc::END] : [],
                );
            }
        }

        foreach (array_filter([$table->note, $table->truncationNote()]) as $note) {
            $section->addText((string) $note, ['size' => 9, 'italic' => true]);
        }
    }

    private function chart(Section $section, ChartSpec $spec, float $widthCm): void
    {
        $type = match ($spec->kind) {
            ChartSpec::LINE => 'line',
            ChartSpec::DONUT => 'doughnut',
            default => 'bar',
        };

        // withoutUnknown() has already removed every null.
        $first = $spec->series[0];
        $chart = $section->addChart(
            $type,
            $spec->categories,
            array_map(static fn (int|float|null $v): int|float => $v ?? 0, $first['values']),
            [
                'width' => Converter::cmToEmu(min($widthCm, 16.5)),
                'height' => Converter::cmToEmu(7.5),
                'showLegend' => true,
                'legendPosition' => $spec->kind === ChartSpec::DONUT ? 'r' : 'b',
                'showAxisLabels' => $spec->kind !== ChartSpec::DONUT,
            ],
            $first['name'],
        );

        foreach (array_slice($spec->series, 1) as $series) {
            $chart->addSeries($spec->categories, array_map(static fn (int|float|null $v): int|float => $v ?? 0, $series['values']), $series['name']);
        }

        $style = $chart->getStyle();
        $style->setColors(array_map(static fn (string $hex): string => ltrim($hex, '#'), $spec->colors));
        $style->setDataLabelOptions(['showVal' => false, 'showCatName' => false, 'showPercent' => false]);
        $style->setShowGridY($spec->kind !== ChartSpec::DONUT);
    }
}
