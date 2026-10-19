<?php

declare(strict_types=1);

namespace App\Domains\Assets\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Asset;
use App\Models\AssetAttachment;
use App\Models\PcUnit;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
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
 *  3. **Server-decided MIME type and kind.** Both come from
 *     {@see AttachmentSecurity::detect()}, which reads the file's own bytes and
 *     re-checks the allow-list. `getClientMimeType()` is never read: it is
 *     attacker-controlled, and persisting it is what previously allowed a
 *     permitted upload to be re-served as `text/html`.
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

        // Detected from the file's own bytes and re-checked against the asset
        // allow-list *before* anything is written, so the value that lands in
        // the database is one the server vouched for.
        $mime = AttachmentSecurity::detect($file, AttachmentSecurity::PROFILE_ASSET);

        // The stored path is composed here, never taken from the upload.
        $path = $file->store("{$folder}/{$target->uuid}", 'local');

        $attachment = DB::transaction(fn (): AssetAttachment => AssetAttachment::query()->create([
            'asset_id' => $isPcUnit ? null : $target->getKey(),
            'pc_unit_id' => $isPcUnit ? $target->getKey() : null,
            'uploaded_by' => $actor->getKey(),
            'kind' => AttachmentSecurity::kindFor($mime),
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
