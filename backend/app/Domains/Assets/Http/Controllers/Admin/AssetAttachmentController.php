<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers\Admin;

use App\Domains\Assets\Actions\AttachAssetFile;
use App\Domains\Assets\Http\Requests\StoreAssetAttachmentRequest;
use App\Domains\Assets\Http\Resources\AssetAttachmentResource;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetAttachment;
use App\Models\PcUnit;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images and documents attached to an asset or PC unit (SRS FR-AST-002).
 *
 * Files live on a **private** disk and are never publicly addressable: the only
 * way to retrieve one is {@see download()}, which re-checks the policy on the
 * owning record every time (NFR-SEC-007/008). That is why the resource exposes a
 * route rather than a storage path — there is no URL to leak.
 *
 * Downloads are streamed rather than read into memory, so a large PDF does not
 * cost a request's worth of RAM.
 */
class AssetAttachmentController extends Controller
{
    /** The gallery for one record. */
    public function index(Request $request, ?Asset $asset = null, ?PcUnit $pcUnit = null): AnonymousResourceCollection
    {
        $target = $this->target($asset, $pcUnit);
        $this->authorize('view', $target);

        return AssetAttachmentResource::collection(
            $target->attachments()->with('uploadedBy')->latest('created_at')->get()
        );
    }

    public function store(
        StoreAssetAttachmentRequest $request,
        AttachAssetFile $action,
        ?Asset $asset = null,
        ?PcUnit $pcUnit = null,
    ): JsonResponse {
        $target = $this->target($asset, $pcUnit);

        /** @var User $actor */
        $actor = $request->user();

        $attachment = $action->handle(
            $target,
            $request->file('file'),
            $actor,
            $request,
            $request->validated('caption'),
        );

        return (new AssetAttachmentResource($attachment->load('uploadedBy')))
            ->additional(['message' => 'Attachment uploaded.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stream one attachment back. Authorization is resolved from the attachment's
     * own owner, so a uuid guessed from elsewhere still fails the policy check.
     */
    public function download(Request $request, AssetAttachment $attachment): StreamedResponse
    {
        $owner = $attachment->asset ?? $attachment->pcUnit;

        if ($owner === null) {
            abort(404);
        }

        $this->authorize('view', $owner);

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->storage_path)) {
            abort(404);
        }

        // Headers are decided by AttachmentSecurity, never by the stored string:
        // a type outside the allow-list — including a row written before that
        // class existed — degrades to an opaque `attachment` download.
        return AttachmentSecurity::stream(
            $disk,
            $attachment->storage_path,
            $attachment->original_filename,
            $attachment->mime_type,
            AttachmentSecurity::PROFILE_ASSET,
        );
    }

    public function destroy(
        Request $request,
        AssetAttachment $attachment,
        AttachAssetFile $action,
    ): JsonResponse {
        $owner = $attachment->asset ?? $attachment->pcUnit;

        if ($owner === null) {
            abort(404);
        }

        $this->authorize('manageAttachments', $owner);

        /** @var User $actor */
        $actor = $request->user();

        $action->detach($owner, $attachment, $actor, $request);

        return response()->json(['message' => 'Attachment removed.']);
    }

    private function target(?Asset $asset, ?PcUnit $pcUnit): Asset|PcUnit
    {
        return $asset ?? $pcUnit ?? throw new RuntimeException('Attachment route bound no target.');
    }
}
