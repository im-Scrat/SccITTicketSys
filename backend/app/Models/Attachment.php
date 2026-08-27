<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to a ticket (SRS FR-TKT-008).
 *
 * Ticket-scoped by design — `ticket_id` is NOT NULL and an `AFTER INSERT OR
 * DELETE` trigger keeps `tickets.attachment_count` accurate. Asset evidence
 * lives in the separate `asset_attachments` table (Phase 2.5, SDD DD-35).
 *
 * The file sits on a private disk; `storage_path` is never a public URL, and
 * downloads stream through a controller that re-checks the owning ticket's
 * visibility on every request (NFR-SEC-007/008).
 *
 * @property int $id
 * @property string $uuid
 * @property int $ticket_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string|null $checksum
 * @property Carbon|null $created_at
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUuidRouteKey;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
