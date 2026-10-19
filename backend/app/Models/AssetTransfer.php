<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssetTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One room-to-room move of a serialized asset (SRS FR-AST-006). Written in the
 * same transaction as the `assets.current_room_id` update, so the register's
 * current answer and its history can never disagree.
 *
 * @property int $id
 * @property int $asset_id
 * @property int|null $from_room_id
 * @property int|null $to_room_id
 * @property int|null $transferred_by
 * @property string|null $reason
 * @property string|null $remarks
 * @property Carbon|null $transferred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetTransfer extends Model
{
    /** @use HasFactory<AssetTransferFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
