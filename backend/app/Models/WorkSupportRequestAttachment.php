<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Supporting evidence on a work support request (SRS FR-WSR-003; SDD DD-53).
 *
 * Shaped like `asset_attachments` and `repair_images` on purpose: same four
 * trust-boundary columns (`mime_type`, `file_size`, `checksum`, `disk`), same
 * private storage, same absence of a public path. FR-WSR-003 requires the
 * *single* attachment boundary, so this carries no upload logic of its own —
 * `AttachmentSecurity` decides what may be stored and how it is served.
 *
 * @property int $id
 * @property string $uuid
 * @property int $work_support_request_id
 * @property int|null $uploaded_by
 * @property string $kind
 * @property string $disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string|null $checksum
 * @property string|null $caption
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkSupportRequest|null $request
 * @property-read User|null $uploadedBy
 */
class WorkSupportRequestAttachment extends Model
{
    use HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    /** @return BelongsTo<WorkSupportRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(WorkSupportRequest::class, 'work_support_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
