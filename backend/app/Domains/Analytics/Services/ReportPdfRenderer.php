<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\DTOs\ReportDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;

/**
 * PDF renderer (Phase 2.10, SDD DD-85/DD-91): a Blade template built from
 * tables only — dompdf lays out neither flex nor grid — with SVG charts, a
 * repeating table header on every page, and page numbers drawn on the canvas.
 *
 * Blade's `{{ }}` escapes every value. Long cells are clipped at 300
 * characters (the full text is in the Excel export) and identifier columns
 * have fixed widths so a ticket number never wraps mid-code.
 */
final class ReportPdfRenderer
{
    public const CELL_LIMIT = 300;

    public function __construct(private readonly ReportChartImageRenderer $charts) {}

    /**
     * @param  array{paper?: string, orientation?: string}  $options
     */
    public function render(ReportDocument $document, array $options = []): string
    {
        $paper = ($options['paper'] ?? 'a4') === 'letter' ? 'letter' : 'a4';
        $orientation = ($options['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';

        $charts = [];
        foreach ($document->sections as $section) {
            foreach ($section->charts as $i => $chart) {
                if (! $chart->isEmpty()) {
                    $charts[$section->key][$i] = $this->charts->dataUri($chart);
                }
            }
        }

        $pdf = Pdf::loadView('reports.pdf.report', [
            'document' => $document,
            'charts' => $charts,
            'cellLimit' => self::CELL_LIMIT,
            'chartWidth' => ReportChartImageRenderer::WIDTH,
            'chartHeight' => ReportChartImageRenderer::HEIGHT,
        ])->setPaper($paper, $orientation);

        /** @var Dompdf $dompdf */
        $dompdf = $pdf->getDomPDF();
        $dompdf->getOptions()->setIsRemoteEnabled(false)->setIsPhpEnabled(false)->setIsJavascriptEnabled(false);
        $dompdf->addInfo('Title', $document->title.' — '.$document->periodLabel);
        $dompdf->addInfo('Author', $document->organisation);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 150,
            $canvas->get_height() - 28,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            8,
            [0.3, 0.3, 0.3],
        );
        $canvas->page_text(
            40,
            $canvas->get_height() - 28,
            $document->organisation.' · '.$document->title,
            $font,
            8,
            [0.3, 0.3, 0.3],
        );

        return (string) $dompdf->output();
    }
}
