<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportLocationMatch;
use App\Enums\ImportLocationResolution;
use App\Support\Concerns\HasUuidRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One distinct location text in an imported file, and the one decision that
 * applies to every row carrying it (Phase 2.10 R6, SDD DD-97). Imports never
 * create locations; they only point at rooms that already exist.
 *
 * @property int $id
 * @property string $uuid
 * @property int $asset_import_id
 * @property string $normalized
 * @property string $source_text
 * @property array<string, string|null> $parts
 * @property ImportLocationMatch $match
 * @property list<array<string, mixed>>|null $candidates
 * @property ImportLocationResolution $resolution
 * @property int|null $room_id
 * @property bool $assign_anyway
 * @property int $row_count
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetImportLocation extends Model
{
    use HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parts' => 'array',
            'match' => ImportLocationMatch::class,
            'candidates' => 'array',
            'resolution' => ImportLocationResolution::class,
            'assign_anyway' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AssetImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AssetImport::class, 'asset_import_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    /** @return HasMany<AssetImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(AssetImportRow::class, 'location_id');
    }
}
