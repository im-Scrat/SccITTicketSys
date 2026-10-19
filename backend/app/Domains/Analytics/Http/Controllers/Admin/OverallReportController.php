<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Http\Requests\OverallRecordsRequest;
use App\Domains\Analytics\Http\Requests\OverallReportRequest;
use App\Domains\Analytics\Services\OverallRecords;
use App\Domains\Analytics\Services\OverallReportService;
use App\Domains\Analytics\Services\ReportsAccess;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Overall Reports (Phase 2.10 R4; SRS FR-RPT-001/002/010; SDD DD-94).
 *
 * Behind the report group's `can:viewAny,ReportArea` gate; each request checks
 * the module of the section or table it asks for, and the service checks
 * again before reading its cache.
 */
class OverallReportController extends Controller
{
    public function section(OverallReportRequest $request, OverallReportService $service): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($service->section($request->section(), $request->overallFilters(), $actor));
    }

    public function records(OverallRecordsRequest $request, OverallRecords $records): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($records->page(
            $request->table(),
            $request->overallFilters(),
            $actor,
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? OverallRecords::PER_PAGE),
            $request->validated('sort'),
            (string) ($request->validated('direction') ?? 'desc'),
        ));
    }

    /**
     * The equipment picker's search: PC units and assets by code or name, at
     * most 20, archived ones marked. Needs `assets.view` as well as reports.
     */
    public function equipmentOptions(Request $request, ReportsAccess $access): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($access->canViewAssets($actor), 403);

        $validated = $request->validate(['q' => ['nullable', 'string', 'max:64']]);
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) ($validated['q'] ?? ''))).'%';

        $pcs = DB::table('pc_units as p')->leftJoin('rooms as r', 'r.id', '=', 'p.room_id')
            ->where(fn ($q) => $q->where('p.unit_code', 'ilike', $term)->orWhere('p.pc_name', 'ilike', $term))
            ->orderBy('p.unit_code')->limit(20)
            ->get(['p.uuid', 'p.unit_code as code', 'p.pc_name as name', 'p.deleted_at', 'r.code as room'])
            ->map(fn (object $r): array => ['value' => 'pc:'.$r->uuid, 'label' => $r->code.' · '.$r->name, 'description' => 'PC unit'.($r->room !== null ? ' · '.$r->room : '').($r->deleted_at !== null ? ' · archived' : ''), 'group' => 'PC units']);

        // Assets are found and labelled by name; the tag is no longer shown (2026-10-19).
        $name = Asset::displayNameSql('a');
        $assets = DB::table('assets as a')->leftJoin('rooms as r', 'r.id', '=', 'a.current_room_id')
            ->where(fn ($q) => $q->whereRaw("{$name} ilike ?", [$term]))
            ->orderByRaw($name)->limit(20)
            ->get(['a.uuid', DB::raw("{$name} as name"), 'a.deleted_at', 'r.code as room'])
            ->map(fn (object $r): array => ['value' => 'asset:'.$r->uuid, 'label' => (string) $r->name, 'description' => 'Other equipment'.($r->room !== null ? ' · '.$r->room : '').($r->deleted_at !== null ? ' · archived' : ''), 'group' => 'Other equipment']);

        return response()->json(['data' => $pcs->concat($assets)->values()]);
    }
}
