<?php

declare(strict_types=1);

namespace App\Domains\Tickets\Actions;

use App\Domains\Assets\Actions\AttachAssetFile;
use App\Domains\Identity\Services\AuditLogger;
use App\Enums\ActivityAction;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Attachments\AttachmentSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Attach evidence to a ticket (SRS FR-TKT-008, NFR-SEC-007/008).
 *
 * Mirrors {@see AttachAssetFile} exactly rather than inventing a second upload
 * design — and, since both once drifted into persisting a client-supplied MIME
 * type, the rules that make a file safe now live in one place both call
 * ({@see AttachmentSecurity}). Two upload paths with different security
 * properties is how one of them ends up being the weak one.
 *
 * The four properties that matter:
 *
 *  1. **Private disk.** `local` is not web-served, so an attachment is reachable
 *     only through the download controller, which re-checks the owning ticket's
 *     visibility on every request.
 *  2. **Server-decided filename.** The stored path is composed here; a
 *     client-supplied name is the classic traversal and double-extension vector.
 *     The original name is kept as *data*, for display.
 *  3. **Server-decided MIME type.** Detected from the bytes and allow-listed by
 *     {@see AttachmentSecurity::detect()}. `getClientMimeType()` is never read.
 *  4. **`attachment_count` is the trigger's.** Never written from PHP.
 */
class AttachTicketFile
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(
        Ticket $ticket,
        UploadedFile $file,
        User $actor,
        Request $request,
    ): Attachment {
        // Detected from the file's own bytes and re-checked against the ticket
        // allow-list *before* anything is written, so the value that lands in
        // the database is one the server vouched for.
        $mime = AttachmentSecurity::detect($file, AttachmentSecurity::PROFILE_TICKET);

        // Composed here, never taken from the upload.
        $path = $file->store("tickets/{$ticket->uuid}", 'local');

        $attachment = Attachment::query()->create([
            'ticket_id' => $ticket->getKey(),
            'uploaded_by' => $actor->getKey(),
            'disk' => 'local',
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'created_at' => now(),
        ]);

        $this->audit->activity(
            ActivityAction::TicketAttachmentAdded,
            actor: $actor,
            subject: $ticket,
            properties: [
                'attachment' => $attachment->uuid,
                'filename' => $attachment->original_filename,
                'size' => $attachment->file_size,
            ],
            request: $request,
            module: 'tickets',
            description: "Attachment {$attachment->original_filename} added to {$ticket->ticket_number}",
        );

        return $attachment->load('uploadedBy');
    }

    /**
     * Remove an attachment and its stored file.
     *
     * `attachments` has no soft delete, so the row goes for real — but the audit
     * entry outlives it, which is what keeps the timeline honest about a piece
     * of evidence having existed and been withdrawn.
     */
    public function detach(Ticket $ticket, Attachment $attachment, User $actor, Request $request): void
    {
        $filename = $attachment->original_filename;
        $uuid = $attachment->uuid;

        Storage::disk($attachment->disk)->delete($attachment->storage_path);
        $attachment->delete();

        $this->audit->activity(
            ActivityAction::TicketAttachmentRemoved,
            actor: $actor,
            subject: $ticket,
            properties: ['attachment' => $uuid, 'filename' => $filename],
            request: $request,
            module: 'tickets',
            description: "Attachment {$filename} removed from {$ticket->ticket_number}",
        );
    }
}
