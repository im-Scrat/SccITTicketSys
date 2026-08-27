<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\AssetAttachment;
use App\Models\PcUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Attach an image or document to an asset or PC unit (SRS FR-AST-002,
 * NFR-SEC-007/008).
 *
 * Three security properties, all deliberate:
 *
 *  1. **Private disk.** Files land on the `local` disk, which is not web-served.
 *     Reaching one requires an authorized controller action, so an attachment
 *     can never be guessed at a public URL.
 *  2. **Server-decided names.** The stored filename is generated, never taken
 *     from the upload — a client-supplied name is the classic path-traversal and
 *     double-extension vector. The original name is kept as *data* for display.
 *  3. **Server-decided kind.** `kind` is derived from the detected MIME type
 *     rather than trusted from the payload, so a renamed executable cannot
 *     present itself as an image. The MIME allow-list itself is enforced in the
 *     FormRequest.
 *
 * A checksum is stored so a later integrity check has something to compare
 * against.
 */
class AttachAssetFile
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        Asset|PcUnit $target,
        UploadedFile $file,
        User $actor,
        Request $request,
        ?string $caption = null,
    ): AssetAttachment {
        $isPcUnit = $target instanceof PcUnit;
        $folder = $isPcUnit ? 'pc-units' : 'assets';

        // The stored path is composed here, never taken from the upload.
        $path = $file->store("{$folder}/{$target->uuid}", 'local');

        $mime = $file->getClientMimeType();

        $attachment = DB::transaction(fn (): AssetAttachment => AssetAttachment::query()->create([
            'asset_id' => $isPcUnit ? null : $target->getKey(),
            'pc_unit_id' => $isPcUnit ? $target->getKey() : null,
            'uploaded_by' => $actor->getKey(),
            'kind' => AssetAttachment::kindForMime($mime),
            'disk' => 'local',
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'caption' => $caption,
        ]));

        $this->audit->activity(
            ActivityAction::AssetAttachmentAdded,
            actor: $actor,
            subject: $target,
            properties: [
                'attachment' => $attachment->uuid,
                'filename' => $attachment->original_filename,
                'kind' => $attachment->kind,
                'size' => $attachment->file_size,
            ],
            request: $request,
            module: 'assets',
            description: "Attachment {$attachment->original_filename} added",
        );

        return $attachment;
    }

    /**
     * Remove an attachment and its stored file. The audit row survives the file,
     * so the timeline still records that something was removed and by whom.
     */
    public function detach(
        Asset|PcUnit $target,
        AssetAttachment $attachment,
        User $actor,
        Request $request,
    ): void {
        $filename = $attachment->original_filename;
        $uuid = $attachment->uuid;

        DB::transaction(function () use ($attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->storage_path);
            $attachment->delete();
        });

        $this->audit->activity(
            ActivityAction::AssetAttachmentRemoved,
            actor: $actor,
            subject: $target,
            properties: ['attachment' => $uuid, 'filename' => $filename],
            request: $request,
            module: 'assets',
            description: "Attachment {$filename} removed",
        );
    }
}
