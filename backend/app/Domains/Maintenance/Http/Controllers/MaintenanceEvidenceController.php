<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Http\Controllers;

use App\Domains\Maintenance\Actions\AttachRepairImage;
use App\Domains\Maintenance\Http\Requests\StoreRepairImageRequest;
use App\Domains\Maintenance\Http\Resources\RepairImageResource;
use App\Enums\RepairImageType;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceRecord;
use App\Models\RepairImage;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Repair evidence on a maintenance record (SRS FR-MNT-005/010; SDD DD-53).
 *
 * Files live on a **private** disk and are never publicly addressable: the only
 * way to retrieve one is {@see download()}, which re-checks the owning record's
 * policy on every request (NFR-SEC-007/008). That is why the resource exposes no
 * storage path — there is no URL to leak.
 *
 * The evidence route is gated on `maintenance.update` — the floor — while
 * `MaintenanceRecordPolicy::manageEvidence()` is what actually decides: the
 * record must be this caller's and the visit must still be open. Gating the
 * route on `maintenance.complete` instead would be wrong in both directions,
 * since attaching a photograph is not completing a job.
 */
class MaintenanceEvidenceController extends Controller
{
    public function __construct(private readonly AttachRepairImage $action) {}

    /** The evidence gallery for one record. */
    public function index(Request $request, MaintenanceRecord $record): AnonymousResourceCollection
    {
        $this->authorize('view', $record);

        return RepairImageResource::collection(
            $record->images()
                ->with('uploadedBy:id,uuid,first_name,last_name')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(StoreRepairImageRequest $request, MaintenanceRecord $record): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $image = $this->action->handle(
            $record,
            $request->file('file'),
            RepairImageType::from($request->validated('image_type')),
            $request->validated('caption'),
            $actor,
            $request,
        );

        return (new RepairImageResource($image))->response()->setStatusCode(201);
    }

    /**
     * Stream one item back to an authorized caller.
     *
     * The image is resolved **through the record**, so a uuid belonging to
     * another visit 404s instead of being served — the same nesting rule the
     * checklist uses, and the reason a guessed identifier gets nowhere.
     */
    public function download(Request $request, MaintenanceRecord $record, RepairImage $image): StreamedResponse
    {
        $this->authorize('view', $record);

        if ($image->maintenance_record_id !== $record->getKey()) {
            abort(404);
        }

        $disk = Storage::disk($image->disk);

        if (! $disk->exists($image->storage_path)) {
            abort(404);
        }

        // Headers are decided by AttachmentSecurity, never by the stored string:
        // a type outside the allow-list — including a row written before that
        // class existed — degrades to an opaque `attachment` download.
        return AttachmentSecurity::stream(
            $disk,
            $image->storage_path,
            $image->original_filename ?? 'evidence',
            $image->mime_type,
            AttachmentSecurity::PROFILE_MAINTENANCE,
        );
    }

    public function destroy(Request $request, MaintenanceRecord $record, RepairImage $image): JsonResponse
    {
        $this->authorize('manageEvidence', $record);

        if ($image->maintenance_record_id !== $record->getKey()) {
            abort(404);
        }

        /** @var User $actor */
        $actor = $request->user();

        $this->action->detach($record, $image, $actor, $request);

        return response()->json(['message' => 'Repair evidence removed.']);
    }
}
