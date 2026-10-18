<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Http\Requests\PrintReportRequest;
use App\Domains\Analytics\Services\ReportChartImageRenderer;
use App\Domains\Analytics\Services\ReportDocumentBuilder;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\ReportContentProfile;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/admin/reports/print (Phase 2.10, SDD DD-91): the document the print
 * page lays out — built by the same builder as every download, held to the
 * same row budget as a PDF, so a printout and a PDF of the same report match.
 * Audited as `ReportPrinted` (asking for a printable document is the act the
 * application can see; what the browser then sends to a printer, it cannot).
 */
class ReportPrintController extends Controller
{
    public function __invoke(PrintReportRequest $request, ReportDocumentBuilder $builder, ReportChartImageRenderer $charts, AuditLogger $audit): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $document = $builder
            ->build($request->reportType(), $request->filters(), $actor, $request->buildOptions(ReportContentProfile::Full))
            ->withRowBudget((int) config('reports.exports.paged_row_budget', 600));

        $audit->activity(
            ActivityAction::ReportPrinted,
            $actor,
            null,
            ['report_type' => $request->reportType()->value, 'rows' => $document->rowCount(), 'filters' => $request->filters()->toQuery()],
            $request,
            'reports',
        );

        // Each chart travels as the very SVG the PDF embeds, as a data: image —
        // allowed by the CSP's img-src, inert inside <img>, and identical to the
        // PDF. Empty charts get none; the page shows the table alone.
        $payload = $document->toArray();
        foreach ($document->sections as $s => $section) {
            foreach ($section->charts as $c => $chart) {
                $payload['sections'][$s]['charts'][$c]['image'] = $chart->isEmpty() ? null : $charts->dataUri($chart);
            }
        }

        return response()->json(['data' => $payload]);
    }
}
