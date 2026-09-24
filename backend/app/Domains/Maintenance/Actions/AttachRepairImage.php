<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Actions;

use App\Domains\Identity\Services\AuditLogger;
use App\Domains\Tickets\Actions\AttachTicketFile;
use App\Enums\ActivityAction;
use App\Enums\RepairImageType;
use App\Models\MaintenanceRecord;
use App\Models\RepairImage;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Attach repair evidence to a maintenance record
 * (SRS FR-MNT-005/010, NFR-SEC-007/008; SDD DD-53).
 *
 * Mirrors {@see AttachTicketFile} line for line rather than inventing a third
 * upload design — which is the whole of DD-53. `repair_images` could not carry
 * a checksum or a server-detected type until DR-021 added the four columns, and
 * a module that cannot record those cannot be behind the same trust boundary as
 * the other two.
 *
 * The four properties that matter, identical to tickets and assets:
 *
 *  1. **Private disk.** `local` is not web-served, so evidence is reachable only
 *     through the download controller, which re-checks the owning record's
 *     policy on every request.
 *  2. **Server-decided filename.** The stored path is composed here; a
 *     client-supplied name is the classic traversal and double-extension
 *     vector. The original name is kept as *data*, for display.
 *  3. **Server-decided MIME type.** Detected from the file's own bytes and
 *     allow-listed by {@see AttachmentSecurity::detect()}.
 *     `getClientMimeType()` is never read.
 *  4. **A stored SHA-256 checksum**, so the bytes served can be shown to be the
 *     bytes uploaded.
 */
class AttachRepairImage
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        MaintenanceRecord $record,
        UploadedFile $file,
        RepairImageType $type,
        ?string $caption,
        User $actor,
        ?Request $request = null,
    ): RepairImage {
        // Detected from the bytes and re-checked against the maintenance
        // allow-list *before* anything is written, so the value that lands in
        // the database is one the server vouched for.
        $mime = AttachmentSecurity::detect($file, AttachmentSecurity::PROFILE_MAINTENANCE);

        // Composed here, never taken from the upload.
        $path = $file->store("maintenance/{$record->uuid}", 'local');

        $image = RepairImage::query()->create([
            'maintenance_record_id' => $record->getKey(),
            'uploaded_by' => $actor->getKey(),
            'image_type' => $type->value,
            'disk' => 'local',
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'created_at' => now(),
        ]);

        $this->audit->activity(
            ActivityAction::MaintenanceEvidenceAdded,
            actor: $actor,
            subject: $record,
            properties: [
                'evidence' => $image->uuid,
                'image_type' => $type->value,
                'filename' => $image->original_filename,
                'size' => $image->file_size,
            ],
            request: $request,
            module: 'maintenance',
            description: "Repair evidence added to {$record->title}",
        );

        return $image->load('uploadedBy:id,uuid,first_name,last_name');
    }

    /**
     * Remove an evidence item and its stored file.
     *
     * `repair_images` has no soft delete, so the row goes for real — but the
     * audit entry outlives it, which is what keeps the timeline honest about a
     * piece of evidence having existed and been withdrawn.
     */
    public function detach(MaintenanceRecord $record, RepairImage $image, User $actor, ?Request $request = null): void
    {
        $filename = $image->original_filename;
        $uuid = $image->uuid;

        Storage::disk($image->disk)->delete($image->storage_path);
        $image->delete();

        $this->audit->activity(
            ActivityAction::MaintenanceEvidenceRemoved,
            actor: $actor,
            subject: $record,
            properties: ['evidence' => $uuid, 'filename' => $filename],
            request: $request,
            module: 'maintenance',
            description: "Repair evidence removed from {$record->title}",
        );
    }
}
