<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Http\Controllers\Admin;

use App\Domains\Analytics\Http\Requests\StoreReportExportRequest;
use App\Domains\Analytics\Http\Resources\ReportExportResource;
use App\Domains\Analytics\Services\ReportExporter;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Enums\ReportContentProfile;
use App\Enums\ReportExportPurpose;
use App\Enums\ReportExportStatus;
use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report document downloads (Phase 2.10; SRS FR-RPT-004; SDD DD-91).
 *
 * Every route here sits behind `can:viewAny,ReportArea` and
 * `can:export,ReportArea`. On top of that, a document belongs to the person
 * who asked for it: another administrator's export answers 404, exactly like
 * one that does not exist, so a uuid reveals nothing. `{export}` is a plain
 * string resolved after authorization for the same reason.
 */
class ReportExportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The requester's recent documents, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();

        return ReportExportResource::collection(
            ReportExport::query()
                ->where('user_id', $actor->getKey())
                ->where('created_at', '>=', now()->subDays(7))
                ->with('deliveries')
                ->latest('id')
                ->limit(20)
                ->get(),
        );
    }

    public function store(StoreReportExportRequest $request, ReportExporter $exporter): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $export = $exporter->request(
            $request->reportType(),
            $request->exportFormat(),
            $request->reportParameters(),
            $request->asOf(),
            $request->documentOptions(),
            ReportContentProfile::Full,
            ReportExportPurpose::Download,
            $actor,
            $request,
        );

        return (new ReportExportResource($export))->response()->setStatusCode($export->wasRecentlyCreated ? 202 : 200);
    }

    public function show(Request $request, string $export): ReportExportResource
    {
        return new ReportExportResource($this->own($request, $export)->load('deliveries'));
    }

    public function download(Request $request, string $export): StreamedResponse|JsonResponse
    {
        $row = $this->own($request, $export);

        if ($row->status->isActive()) {
            return response()->json(['message' => 'The file is still being prepared.'], 409);
        }

        if (! $row->isDownloadable()) {
            return response()->json([
                'message' => $row->status === ReportExportStatus::Failed
                    ? 'This file could not be produced. Ask for it again.'
                    : 'This file has expired. Ask for it again.',
            ], 410);
        }

        $disk = Storage::disk($row->disk);

        if (! $disk->exists((string) $row->path)) {
            return response()->json(['message' => 'This file has expired. Ask for it again.'], 410);
        }

        $row->newQuery()->whereKey($row->getKey())->update([
            'download_count' => $row->download_count + 1,
            'last_downloaded_at' => now(),
        ]);

        $this->audit->activity(
            ActivityAction::ReportDownloaded,
            $request->user(),
            $row,
            ['report_type' => $row->report_type->value, 'format' => $row->format->value, 'bytes' => $row->size_bytes],
            $request,
            'reports',
        );

        return AttachmentSecurity::stream(
            $disk,
            (string) $row->path,
            (string) $row->filename,
            $row->format->mime(),
            AttachmentSecurity::PROFILE_REPORT,
        );
    }

    /** The caller's own document, or 404 — never a hint that someone else's exists. */
    private function own(Request $request, string $uuid): ReportExport
    {
        /** @var User $actor */
        $actor = $request->user();

        return ReportExport::query()
            ->where('uuid', $uuid)
            ->where('user_id', $actor->getKey())
            ->firstOrFail();
    }
}
