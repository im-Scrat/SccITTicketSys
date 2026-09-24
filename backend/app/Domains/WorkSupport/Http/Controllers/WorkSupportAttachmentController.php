<?php

declare(strict_types=1);

namespace App\Domains\WorkSupport\Http\Controllers;

use App\Domains\Maintenance\Http\Controllers\MaintenanceEvidenceController;
use App\Http\Controllers\Controller;
use App\Models\WorkSupportRequest;
use App\Models\WorkSupportRequestAttachment;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Retrieving evidence attached to a work support request
 * (SRS FR-WSR-003/005; SDD DD-45, DD-53).
 *
 * Stage E stored these files correctly — private disk, server-detected type,
 * SHA-256 checksum — and gave nobody a way to read them back, which left
 * FR-WSR-005's *"not a bare notification"* half-true: an administrator could see
 * that a photograph existed and not open it. This is the missing half, and
 * nothing more.
 *
 * ── The route shape is the authorization ───────────────────────────────────
 *
 * The attachment is addressed **through its request**:
 *
 *     GET /work-support-requests/{request:uuid}/attachments/{attachment:uuid}
 *
 * There is deliberately **no** `GET /attachments/{uuid}`. A flat endpoint would
 * make the attachment's own identifier sufficient for access, and an identifier
 * is not an entitlement — the same rule the whole QR workflow is built on
 * (DD-47). Here the parent is authorized first and the child must belong to it,
 * so a guessed or harvested attachment uuid gets nowhere: it is refused
 * identically whether it belongs to another request, another technician, or to
 * nothing at all.
 *
 * ── Mirrors the maintenance evidence path exactly ──────────────────────────
 *
 * Line for line the same as {@see MaintenanceEvidenceController::download()},
 * on the same `PROFILE_MAINTENANCE`. FR-WSR-003 requires the system's *single*
 * attachment trust boundary; a second download implementation would be a second
 * boundary however carefully it was written, so this one adds no decision of its
 * own — `AttachmentSecurity::stream()` picks the content type and the
 * disposition from the **stored, server-detected** value, and a type outside the
 * allow-list degrades to an opaque `application/octet-stream` attachment.
 *
 * ── No audit row ───────────────────────────────────────────────────────────
 *
 * A download is a read. `activity_logs` records actor-initiated *business
 * events*, and logging reads would double the audit surface for no gain — the
 * split this project has drawn since `login_history`, and the same reason a QR
 * scan writes no activity row.
 */
class WorkSupportAttachmentController extends Controller
{
    public function download(
        Request $request,
        WorkSupportRequest $workSupportRequest,
        WorkSupportRequestAttachment $attachment,
    ): StreamedResponse {
        /*
         * The parent first. `view` delegates to `WorkSupportVisibility::canSee()`
         * — the same rule the list and the detail page use — so an administrator
         * reaches any request and a technician only their own. No new permission
         * and no new ability: reading the evidence is part of reading the
         * request.
         */
        $this->authorize('view', $workSupportRequest);

        /*
         * Then the nesting. A 404 rather than a 403: at this point the caller is
         * authorized for the request they named, so the honest answer about an
         * attachment that is not part of it is "no such thing here" — and it is
         * the identical answer for an attachment that never existed, which is
         * what stops this from confirming that someone else's uuid is real.
         */
        if ($attachment->work_support_request_id !== $workSupportRequest->getKey()) {
            abort(404);
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->storage_path)) {
            // A row whose bytes are gone is a controlled 404, not a stack trace.
            abort(404);
        }

        return AttachmentSecurity::stream(
            $disk,
            $attachment->storage_path,
            $attachment->original_filename,
            $attachment->mime_type,
            AttachmentSecurity::PROFILE_MAINTENANCE,
        );
    }
}
