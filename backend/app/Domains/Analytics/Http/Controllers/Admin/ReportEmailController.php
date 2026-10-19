<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Http\Requests\CheckReportRecipientsRequest;
use App\Domains\Analytics\Http\Requests\StoreReportEmailRequest;
use App\Domains\Analytics\Http\Resources\ReportExportResource;
use App\Domains\Analytics\Services\ReportEmailer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Emailing a report (Phase 2.10, owner decision D1, SDD DD-92). Two steps on
 * purpose: `check-recipients` tells the administrator which addresses are
 * outside the school and exactly what would be sent; only then does `store`
 * accept the request — and only if it confirms those outside addresses.
 */
class ReportEmailController extends Controller
{
    public function check(CheckReportRecipientsRequest $request, ReportEmailer $emailer): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $emailer->preview($request->reportType(), $request->exportFormat(), $request->recipients(), $actor),
        ]);
    }

    public function store(StoreReportEmailRequest $request, ReportEmailer $emailer): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $export = $emailer->request(
            $request->reportType(),
            $request->exportFormat(),
            $request->reportParameters(),
            $request->asOf(),
            $request->documentOptions(),
            $request->recipients(),
            $actor,
            $request,
        );

        return (new ReportExportResource($export->load('deliveries')))->response()->setStatusCode(202);
    }
}
