<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetStatus;
use Database\Factories\AssetStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lifecycle transition (SRS FR-AST-005). Append-only: `UPDATED_AT` is
 * disabled because a history row is never edited, only added.
 *
 * `from_status` is null on the opening row — the asset had no previous state.
 *
 * @property int $id
 * @property int $asset_id
 * @property AssetStatus|null $from_status
 * @property AssetStatus $to_status
 * @property int|null $changed_by
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class AssetStatusHistory extends Model
{
    /** @use HasFactory<AssetStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'asset_status_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_status' => AssetStatus::class,
            'to_status' => AssetStatus::class,
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
