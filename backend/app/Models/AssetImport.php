<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetImportEntityMode;
use App\Enums\AssetImportOutcome;
use App\Enums\AssetImportStatus;
use App\Enums\ImportDateFormat;
use App\Support\Concerns\HasUuidRouteKey;
use Database\Factories\AssetImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One spreadsheet's journey into the asset register (Phase 2.10 R6, SRS
 * FR-AST-016, SDD DD-96).
 *
 * `revision` increases with every change an administrator makes to the
 * mapping, the rows or a decision; confirm carries the revision it saw, so a
 * plan that changed underneath a confirmation is refused (409) rather than
 * saved. `plan` is the counts the administrator confirmed, and `result` the
 * counts the save produced — the finalizer compares the two to decide the
 * outcome, which is stored once and never recomputed by a client.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $created_by
 * @property AssetImportEntityMode $entity_mode
 * @property AssetImportStatus $status
 * @property AssetImportOutcome|null $outcome
 * @property int $revision
 * @property string $original_filename
 * @property string $extension
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property string $disk
 * @property string|null $path
 * @property Carbon|null $file_deleted_at
 * @property array<string, mixed>|null $source_options
 * @property array<string, mixed>|null $source_meta
 * @property list<array<string, mixed>>|null $headers
 * @property array<array-key, string|null>|null $mapping
 * @property array<string, array<string, string|null>>|null $value_mappings
 * @property array<string, mixed>|null $defaults
 * @property ImportDateFormat|null $date_order
 * @property int $total_rows
 * @property array<string, int>|null $row_counts
 * @property string|null $error_code
 * @property array<string, mixed>|null $plan
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property string|null $confirm_ip
 * @property string|null $confirm_user_agent
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $stop_requested_at
 * @property int|null $stopped_by
 * @property array<string, mixed>|null $result
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property int|null $reviewed_created_count
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetImport extends Model
{
    /** @use HasFactory<AssetImportFactory> */
    use HasFactory, HasUuidRouteKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entity_mode' => AssetImportEntityMode::class,
            'status' => AssetImportStatus::class,
            'outcome' => AssetImportOutcome::class,
            'date_order' => ImportDateFormat::class,
            'source_options' => 'array',
            'source_meta' => 'array',
            'headers' => 'array',
            'mapping' => 'array',
            'value_mappings' => 'array',
            'defaults' => 'array',
            'row_counts' => 'array',
            'plan' => 'array',
            'result' => 'array',
            'file_deleted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stop_requested_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return HasMany<AssetImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(AssetImportRow::class);
    }

    /** @return HasMany<AssetImportLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(AssetImportLocation::class);
    }

    /** @return HasMany<AssetImportCatalogEntry, $this> */
    public function catalogEntries(): HasMany
    {
        return $this->hasMany(AssetImportCatalogEntry::class);
    }

    /** The original file is still on the private disk. */
    public function hasFile(): bool
    {
        return $this->path !== null && $this->file_deleted_at === null;
    }

    /** The mapped target for a column index, if any. */
    public function targetFor(int $column): ?string
    {
        $target = $this->mapping[(string) $column] ?? null;

        return is_string($target) && $target !== '' ? $target : null;
    }
}
