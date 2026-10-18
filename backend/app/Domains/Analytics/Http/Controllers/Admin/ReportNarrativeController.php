<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Http\Requests\OverallReportRequest;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Domains\KnowledgeBase\Services\ReportNarrativeGenerator;
use App\Http\Controllers\Controller;
use App\Models\ReportNarrative;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * AI interpretations of the overall report (Phase 2.10 R5; SRS FR-RPT-011;
 * SDD DD-95).
 *
 * The recorded facts are shown whether or not AI is on — they are the
 * application's own figures. Asking for an interpretation is rate-limited per
 * administrator on top of the shared AI governor, and needs every module the
 * facts come from (tickets, equipment, repairs).
 */
class ReportNarrativeController extends Controller
{
    public function current(OverallReportRequest $request, ReportNarrativeGenerator $generator, ReportsAccess $access): JsonResponse
    {
        $this->authorizeInsights($request, $access);
        $state = $generator->state($request->overallFilters());

        return response()->json(['data' => [
            'enabled' => $state['enabled'],
            'configured' => $state['configured'],
            'current' => $state['current'],
            'changed_fact_keys' => $state['changed_fact_keys'],
            'facts' => $state['packet']->toArray(),
            'narrative' => $state['narrative'] !== null ? $this->present($state['narrative']) : null,
        ]]);
    }

    public function generate(OverallReportRequest $request, ReportNarrativeGenerator $generator, ReportsAccess $access): JsonResponse
    {
        $this->authorizeInsights($request, $access);
        /** @var User $actor */
        $actor = $request->user();

        $narrative = $generator->request($request->overallFilters(), $actor);

        return response()->json(['data' => $this->present($narrative)], $narrative->wasRecentlyCreated ? 202 : 200);
    }

    private function authorizeInsights(OverallReportRequest $request, ReportsAccess $access): void
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($access->canViewInsights($actor), 403, 'AI insights need ticket, equipment and repair access.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ReportNarrative $narrative): array
    {
        return [
            'id' => $narrative->uuid,
            'status' => $narrative->status->value,
            'status_label' => $narrative->status->label(),
            'model' => $narrative->model,
            'coverage' => $narrative->coverage,
            'findings' => $narrative->findings ?? [],
            'limitations' => $narrative->limitations ?? [],
            'withheld_count' => $narrative->withheld_count,
            'error_code' => $narrative->error_code,
            'generated_at' => $narrative->generated_at?->toIso8601ZuluString(),
            'created_at' => $narrative->created_at?->toIso8601ZuluString(),
        ];
    }
}
