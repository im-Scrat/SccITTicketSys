<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image or document attached to an asset or a PC unit (SRS FR-AST-002).
 *
 * Exactly one target is bound, enforced by the `asset_attachments_target_check`
 * database constraint (SDD DD-35) — the same discipline `qr_codes` uses. The
 * file itself lives on a private disk; `storage_path` is never a public URL, and
 * downloads stream through an authorized controller (NFR-SEC-007/008).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $asset_id
 * @property int|null $pc_unit_id
 * @property int|null $uploaded_by
 * @property string $kind
 * @property string $disk
 * @property string $storage_path
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string|null $checksum
 * @property string|null $caption
 */
class AssetAttachment extends Model
{
    use HasUuidRouteKey;

    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<PcUnit, $this> */
    public function pcUnit(): BelongsTo
    {
        return $this->belongsTo(PcUnit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    /** Derive the stored `kind` from an uploaded file's MIME type. */
    public static function kindForMime(?string $mime): string
    {
        return $mime !== null && str_starts_with($mime, 'image/')
            ? self::KIND_IMAGE
            : self::KIND_DOCUMENT;
    }
}
